<?php

namespace App\Actions\Import;

use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameLogBinary;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Jobs\DetermineMatchArchetypesJob;
use App\Models\Card;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\ImportScan;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Support\Str;

class ImportMatches
{
    /**
     * Import selected matches into the database.
     *
     * @param  array<int, array>  $matches
     * @return array{imported: int, skipped: int}
     */
    public static function run(array $matches): array
    {
        $dataPath = Mtgo::getLogDataPath();
        $imported = 0;
        $skipped = 0;

        // Ensure all card stubs have oracle_ids before we compute stats.
        // The scan creates stubs but API population can fail silently.
        self::ensureCardsPopulated($matches);

        foreach ($matches as $data) {
            if (MtgoMatch::where('mtgo_id', (string) $data['history_id'])->exists()) {
                $skipped++;

                continue;
            }

            $outcome = match ($data['outcome']) {
                'win' => MatchOutcome::Win,
                'loss' => MatchOutcome::Loss,
                'draw' => MatchOutcome::Draw,
                default => MatchOutcome::Unknown,
            };

            $lastGameEnd = null;
            if (! empty($data['games'])) {
                $lastGameEnd = collect($data['games'])->pluck('ended_at')->filter()->last();
            }

            $match = MtgoMatch::create([
                'token' => Str::uuid()->toString(),
                'mtgo_id' => (string) $data['history_id'],
                'deck_version_id' => $data['deck_version_id'] ?? null,
                'format' => $data['format_raw'],
                'match_type' => $data['round'] > 0 ? 'League' : 'Constructed',
                'games_won' => $data['games_won'],
                'games_lost' => $data['games_lost'],
                'started_at' => $data['started_at'],
                'ended_at' => $lastGameEnd,
                'state' => MatchState::Complete,
                'outcome' => $outcome,
                'imported' => true,
            ]);

            // Create GameLog record if we have a game log token
            if ($data['game_log_token'] ?? null) {
                self::createGameLog($match, $data['game_log_token'], $dataPath);
            }

            if (! empty($data['games']) && $data['local_player']) {
                self::createGames($match, $data);

                // Dispatch archetype detection to the queue — external API calls are too slow for inline
                DetermineMatchArchetypesJob::dispatch($match->id)->onQueue('match_archetypes');
            }

            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Import matches from a completed scan.
     *
     * @param  array<int>|null  $historyIds  Specific matches to import, or null for all
     * @return array{imported: int, skipped: int}
     */
    public static function runFromScan(ImportScan $scan, ?array $historyIds = null): array
    {
        $query = $scan->matches();

        if ($historyIds !== null) {
            $query->whereIn('history_id', $historyIds);
        }

        $scanMatches = $query->get();
        $imported = 0;
        $skipped = 0;

        foreach ($scanMatches as $scanMatch) {
            if (MtgoMatch::where('mtgo_id', (string) $scanMatch->history_id)->exists()) {
                $skipped++;

                continue;
            }

            $outcome = match ($scanMatch->outcome) {
                'win' => MatchOutcome::Win,
                'loss' => MatchOutcome::Loss,
                'draw' => MatchOutcome::Draw,
                default => MatchOutcome::Unknown,
            };

            // Try to build game data from the game log
            $games = null;
            $localPlayer = $scanMatch->local_player;
            $opponent = $scanMatch->opponent;
            $localCards = [];
            $opponentCards = [];

            if ($scanMatch->game_log_token && $localPlayer) {
                $gameLog = GameLog::where('match_token', $scanMatch->game_log_token)
                    ->whereNotNull('decoded_entries')
                    ->first();

                if ($gameLog?->decoded_entries) {
                    $cardData = ExtractCardsFromGameLog::run($gameLog->decoded_entries);
                    $localCards = $cardData['cards_by_player'][$localPlayer] ?? [];
                    $opponentCards = $cardData['cards_by_player'][$opponent] ?? [];

                    $gameResults = ExtractGameResults::run($gameLog->decoded_entries, $localPlayer);
                    $cardsByGame = $cardData['cards_by_game'] ?? [];
                    $gameMeta = $cardData['game_meta'] ?? [];

                    $games = collect($gameResults['games'])->map(function ($g) use ($localPlayer, $opponent, $cardsByGame, $gameMeta) {
                        $gameCards = $cardsByGame[$g['game_index']] ?? [];
                        $meta = $gameMeta[$g['game_index']] ?? [];

                        return [
                            'game_index' => $g['game_index'],
                            'won' => $g['winner'] === $localPlayer,
                            'on_play' => $g['on_play'] === $localPlayer,
                            'starting_hand_size' => $g['starting_hands'][$localPlayer] ?? 7,
                            'opponent_hand_size' => $g['starting_hands'][$opponent] ?? 7,
                            'started_at' => $g['started_at'],
                            'ended_at' => $g['ended_at'],
                            'local_cards' => $gameCards[$localPlayer] ?? [],
                            'opponent_cards' => $gameCards[$opponent] ?? [],
                            'local_dice_roll' => $meta['dice_rolls'][$localPlayer] ?? null,
                            'opponent_dice_roll' => $meta['dice_rolls'][$opponent] ?? null,
                            'local_mulligan_count' => $meta['mulligans'][$localPlayer] ?? 0,
                            'opponent_mulligan_count' => $meta['mulligans'][$opponent] ?? 0,
                            'turn_count' => $meta['turn_count'] ?? null,
                        ];
                    })->toArray();
                }
            }

            // Hydrate card stubs so oracle_ids are available for archetype detection
            $cardsToHydrate = collect($localCards)
                ->merge($opponentCards)
                ->map(fn ($c) => ['mtgo_id' => $c['mtgo_id'], 'name' => $c['name']])
                ->unique('mtgo_id')
                ->values()
                ->toArray();

            if (! empty($cardsToHydrate)) {
                self::hydrateCards($cardsToHydrate);
            }

            $lastGameEnd = null;
            if (! empty($games)) {
                $lastGameEnd = collect($games)->pluck('ended_at')->filter()->last();
            }

            $match = MtgoMatch::create([
                'token' => Str::uuid()->toString(),
                'mtgo_id' => (string) $scanMatch->history_id,
                'deck_version_id' => $scan->deck_version_id,
                'format' => $scanMatch->format,
                'match_type' => $scanMatch->round > 0 ? 'League' : 'Constructed',
                'games_won' => $scanMatch->games_won,
                'games_lost' => $scanMatch->games_lost,
                'started_at' => $scanMatch->started_at,
                'ended_at' => $lastGameEnd,
                'state' => MatchState::Complete,
                'outcome' => $outcome,
                'imported' => true,
            ]);

            // Link existing GameLog to this match
            if ($scanMatch->game_log_token) {
                GameLog::where('match_token', $scanMatch->game_log_token)
                    ->update(['match_token' => $match->token]);
            }

            if (! empty($games) && $localPlayer) {
                $data = [
                    'local_player' => $localPlayer,
                    'opponent' => $opponent,
                    'local_cards' => $localCards,
                    'opponent_cards' => $opponentCards,
                    'games' => $games,
                    'game_ids' => $scanMatch->game_ids ?? [],
                ];

                self::createGames($match, $data);
                DetermineMatchArchetypesJob::dispatch($match->id)->onQueue('match_archetypes');
            }

            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Create Game records and attach players for a given match.
     */
    private static function createGames(MtgoMatch $match, array $data): void
    {
        $localPlayer = Player::firstOrCreate(['username' => $data['local_player']]);
        $opponent = Player::firstOrCreate(['username' => $data['opponent']]);

        // Collect ALL cards (local + opponent) for stats tracking.
        // ComputeImportedCardGameStats filters against deck oracle_ids, so opponent
        // cards that aren't in the deck are excluded naturally.
        $allMatchCards = collect($data['local_cards'] ?? [])
            ->merge($data['opponent_cards'] ?? [])
            ->unique('mtgo_id')
            ->values()
            ->toArray();

        foreach ($data['games'] as $index => $gameData) {
            $gameId = $data['game_ids'][$index] ?? null;

            // Per-game: combine local + opponent cards for this game
            $gameLocalCards = $gameData['local_cards'] ?? [];
            $gameOpponentCards = $gameData['opponent_cards'] ?? [];
            $cardStats = ! empty($gameLocalCards) || ! empty($gameOpponentCards)
                ? collect($gameLocalCards)->merge($gameOpponentCards)
                    ->unique('mtgo_id')
                    ->values()
                    ->toArray()
                : collect($allMatchCards)
                    ->values()
                    ->toArray();

            // Build deck_json from per-game cards extracted from game log
            $buildDeckJson = fn ($cards) => ! empty($cards)
                ? collect($cards)->map(fn ($card) => [
                    'mtgo_id' => $card['mtgo_id'],
                    'quantity' => 1,
                    'sideboard' => false,
                ])->values()->toArray()
                : null;

            $localDeckJson = $buildDeckJson($gameData['local_cards'] ?? []);
            $opponentDeckJson = $buildDeckJson($gameData['opponent_cards'] ?? []);

            $game = Game::create([
                'match_id' => $match->id,
                'mtgo_id' => $gameId ? (string) $gameId : Str::uuid()->toString(),
                'won' => $gameData['won'] ?? null,
                'started_at' => $gameData['started_at'],
                'ended_at' => $gameData['ended_at'],
                'turn_count' => $gameData['turn_count'] ?? null,
            ]);

            $game->players()->attach($localPlayer->id, [
                'is_local' => true,
                'on_play' => $gameData['on_play'] ?? false,
                'starting_hand_size' => $gameData['starting_hand_size'] ?? 7,
                'instance_id' => 0,
                'deck_json' => $localDeckJson,
                'dice_roll' => $gameData['local_dice_roll'] ?? null,
                'mulligan_count' => $gameData['local_mulligan_count'] ?? 0,
            ]);

            $game->players()->attach($opponent->id, [
                'is_local' => false,
                'on_play' => ! ($gameData['on_play'] ?? false),
                'starting_hand_size' => $gameData['opponent_hand_size'] ?? 7,
                'instance_id' => 0,
                'deck_json' => $opponentDeckJson,
                'dice_roll' => $gameData['opponent_dice_roll'] ?? null,
                'mulligan_count' => $gameData['opponent_mulligan_count'] ?? 0,
            ]);

            if ($match->deck_version_id && $game->won !== null) {
                ComputeImportedCardGameStats::run(
                    $game,
                    $match->deck_version_id,
                    $cardStats,
                    isPostboard: $index > 0
                );
            }
        }
    }

    /**
     * Create card stubs and resolve oracle_ids for the given cards.
     *
     * 1. Creates stubs for unknown mtgo_ids
     * 2. Tries the API to populate them
     * 3. Falls back to name-based oracle_id resolution for any the API doesn't know
     *
     * @param  array<int, array{mtgo_id: int, name: string}>  $cards
     */
    public static function hydrateCards(array $cards): void
    {
        // Build mtgo_id => name map from the input
        $cardNames = [];
        foreach ($cards as $card) {
            $cardNames[$card['mtgo_id']] = $card['name'];
        }

        if (empty($cardNames)) {
            return;
        }

        $mtgoIds = array_keys($cardNames);

        // Create stubs for any cards not yet in the DB
        $existing = Card::whereIn('mtgo_id', $mtgoIds)->pluck('mtgo_id')->toArray();
        $missing = array_diff($mtgoIds, $existing);

        if (! empty($missing)) {
            Card::insert(
                collect($missing)->map(fn ($id) => [
                    'mtgo_id' => $id,
                    'name' => $cardNames[$id] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->toArray()
            );
        }

        // Try the API for any stubs missing oracle_ids
        PopulateCardsInChunks::run();

        // Fall back: resolve oracle_ids by name for cards the API doesn't know.
        // Game logs give us @[CardName@:CatalogID,...] so we know the name even
        // when the API hasn't indexed this particular printing.
        $stillMissing = Card::whereIn('mtgo_id', $mtgoIds)
            ->whereNull('oracle_id')
            ->get();

        if ($stillMissing->isEmpty()) {
            return;
        }

        // Build name → oracle_id lookup from cards we DO have
        $knownOracles = Card::whereNotNull('oracle_id')
            ->whereNotNull('name')
            ->get()
            ->groupBy('name')
            ->map(fn ($cards) => $cards->first()->oracle_id);

        foreach ($stillMissing as $card) {
            $name = $card->name ?: ($cardNames[$card->mtgo_id] ?? null);

            if (! $name) {
                continue;
            }

            $oracleId = $knownOracles->get($name);

            if ($oracleId) {
                $card->update(array_filter([
                    'name' => $card->name ?: $name,
                    'oracle_id' => $oracleId,
                ]));
            }
        }
    }

    /**
     * Ensure all card mtgo_ids referenced by these matches have oracle_ids.
     */
    private static function ensureCardsPopulated(array $matches): void
    {
        $cards = [];

        foreach ($matches as $data) {
            foreach ($data['local_cards'] ?? [] as $card) {
                $cards[$card['mtgo_id']] = ['mtgo_id' => $card['mtgo_id'], 'name' => $card['name']];
            }
            foreach ($data['opponent_cards'] ?? [] as $card) {
                $cards[$card['mtgo_id']] = ['mtgo_id' => $card['mtgo_id'], 'name' => $card['name']];
            }
            foreach ($data['games'] ?? [] as $game) {
                foreach ($game['local_cards'] ?? [] as $card) {
                    $cards[$card['mtgo_id']] = ['mtgo_id' => $card['mtgo_id'], 'name' => $card['name']];
                }
                foreach ($game['opponent_cards'] ?? [] as $card) {
                    $cards[$card['mtgo_id']] = ['mtgo_id' => $card['mtgo_id'], 'name' => $card['name']];
                }
            }
        }

        self::hydrateCards(array_values($cards));
    }

    private static function createGameLog(MtgoMatch $match, string $gameLogToken, string $dataPath): void
    {
        $filePath = $dataPath.'/Match_GameLog_'.$gameLogToken.'.dat';

        if (! file_exists($filePath)) {
            return;
        }

        // Link existing decoded GameLog if discovery already processed this file
        $existing = GameLog::where('match_token', $gameLogToken)
            ->whereNotNull('decoded_entries')
            ->first();

        if ($existing) {
            $existing->update(['match_token' => $match->token]);

            return;
        }

        $raw = file_get_contents($filePath);
        $parsed = ParseGameLogBinary::run($raw);

        if (! $parsed || empty($parsed['entries'])) {
            return;
        }

        GameLog::create([
            'match_token' => $match->token,
            'file_path' => $filePath,
            'decoded_entries' => $parsed['entries'],
            'decoded_at' => now(),
            'byte_offset' => $parsed['byte_offset'],
            'decoded_version' => ParseGameLogBinary::VERSION,
        ]);
    }
}
