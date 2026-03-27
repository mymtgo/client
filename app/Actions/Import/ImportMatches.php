<?php

namespace App\Actions\Import;

use App\Actions\Matches\ParseGameLogBinary;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Jobs\DetermineMatchArchetypesJob;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Support\Str;

class ImportMatches
{
    /**
     * Import selected matches into the database.
     *
     * @param  array<int, array>  $matches
     * @param  string|null  $dataPath  Override data path (for dev/testing)
     * @return array{imported: int, skipped: int}
     */
    public static function run(array $matches, ?string $dataPath = null): array
    {
        $dataPath ??= config('mymtgo.import_data_path') ?: Mtgo::getLogDataPath();
        $imported = 0;
        $skipped = 0;

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
                DetermineMatchArchetypesJob::dispatch($match->id);
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

        // Fall back to match-level cards if per-game cards not available
        $matchSeenMtgoIds = collect($data['local_cards'] ?? [])->pluck('mtgo_id')->toArray();

        foreach ($data['games'] as $index => $gameData) {
            $gameId = $data['game_ids'][$index] ?? null;

            // Per-game seen cards (fall back to match-level aggregate)
            $gameLocalCards = $gameData['local_cards'] ?? null;
            $seenMtgoIds = $gameLocalCards !== null
                ? collect($gameLocalCards)->pluck('mtgo_id')->toArray()
                : $matchSeenMtgoIds;

            // Build opponent deck_json from per-game opponent cards
            $opponentDeckJson = null;
            $gameOpponentCards = $gameData['opponent_cards'] ?? null;
            if ($gameOpponentCards !== null && ! empty($gameOpponentCards)) {
                $opponentDeckJson = collect($gameOpponentCards)->map(fn ($card) => [
                    'mtgo_id' => $card['mtgo_id'],
                    'quantity' => 1,
                    'sideboard' => false,
                ])->values()->toArray();
            }

            $game = Game::create([
                'match_id' => $match->id,
                'mtgo_id' => $gameId ? (string) $gameId : Str::uuid()->toString(),
                'won' => $gameData['won'] ?? null,
                'started_at' => $gameData['started_at'],
                'ended_at' => $gameData['ended_at'],
            ]);

            $game->players()->attach($localPlayer->id, [
                'is_local' => true,
                'on_play' => $gameData['on_play'] ?? false,
                'starting_hand_size' => $gameData['starting_hand_size'] ?? 7,
                'instance_id' => 0,
                'deck_json' => null,
            ]);

            $game->players()->attach($opponent->id, [
                'is_local' => false,
                'on_play' => ! ($gameData['on_play'] ?? false),
                'starting_hand_size' => $gameData['opponent_hand_size'] ?? 7,
                'instance_id' => 0,
                'deck_json' => $opponentDeckJson,
            ]);

            if ($match->deck_version_id && $game->won !== null) {
                ComputeImportedCardGameStats::run(
                    $game,
                    $match->deck_version_id,
                    $seenMtgoIds,
                    isPostboard: $index > 0
                );
            }
        }
    }

    private static function createGameLog(MtgoMatch $match, string $gameLogToken, string $dataPath): void
    {
        $filePath = $dataPath.'/Match_GameLog_'.$gameLogToken.'.dat';

        if (! file_exists($filePath)) {
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
