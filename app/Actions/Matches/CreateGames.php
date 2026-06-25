<?php

namespace App\Actions\Matches;

use App\Actions\Cards\CreateMissingCards;
use App\Actions\Logs\ConvertMtgoTimestamp;
use App\Actions\Util\ExtractJson;
use App\Facades\Mtgo;
use App\Models\Account;
use App\Models\Game;
use App\Models\GameDeck;
use App\Models\GameTimeline;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CreateGames
{
    public static function run(MtgoMatch $match, int $gameId, Collection $gameEvents, int $gameIndex, array $playerDeck)
    {
        $gameStateEvents = $gameEvents->filter(
            fn (LogEvent $event) => $event->event_type == 'game_state_update'
        );

        // Pull per-game data from the stored decoded game log when available.
        // SyncGamePivots applies these values; CreateGames is responsible
        // only for the existence of the Game and its pivot rows.
        [$gameData, $username] = self::extractPerGameData($match, $gameIndex);

        $firstStateEvent = $gameStateEvents->first();
        $lastStateEvent = $gameStateEvents->last();

        $gameModel = Game::where('mtgo_id', $gameId)->firstOrCreate([
            'match_id' => $match->id,
            'mtgo_id' => $gameId,
        ], [
            'started_at' => $firstStateEvent
                ? ConvertMtgoTimestamp::run($firstStateEvent->logged_at, $firstStateEvent->timestamp)
                : null,
            'ended_at' => null,
        ]);

        if (! $firstStateEvent) {
            Log::channel('pipeline')->info("CreateGames: no state events yet for game {$gameId} in match {$match->mtgo_id}");

            if ($username) {
                SyncGamePivots::forGame($gameModel, $gameData, $username);
            }

            return;
        }

        $parsedState = ExtractJson::run($firstStateEvent->raw_text)->first();

        if (! $parsedState) {
            Log::channel('pipeline')->warning("CreateGames: could not parse state event for game {$gameId} in match {$match->mtgo_id}");

            return;
        }

        $players = $parsedState['Players'] ?? [];

        if (empty($players)) {
            Log::channel('pipeline')->warning("CreateGames: no players found in state event for game {$gameId} in match {$match->mtgo_id}");

            return;
        }

        self::upsertParticipants($match, $gameModel, $players, $parsedState, $lastStateEvent, $playerDeck, $username);

        if ($username) {
            SyncGamePivots::forGame($gameModel, $gameData, $username);
        }

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: game {$gameId} — ".($gameModel->wasRecentlyCreated ? 'created' : 'updated').", {$gameModel->decks()->count()} participants synced");

        self::replaceTimeline($gameModel, $gameStateEvents);
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: ?string}
     */
    private static function extractPerGameData(MtgoMatch $match, int $gameIndex): array
    {
        $entries = ExtractMetaMessageEntries::run($match->token);

        if (empty($entries)) {
            return [null, Mtgo::resolveUsername()];
        }

        $candidates = ExtractGameResults::detectPlayers($entries);
        $username = Mtgo::resolveUsername($candidates);

        if (! $username) {
            return [null, null];
        }

        $extracted = ExtractGameResults::run($entries, $username);
        $gameData = $extracted['games'][$gameIndex] ?? null;

        return [$gameData, $username];
    }

    /**
     * Write new-schema participant data for each player in the parsed game state.
     *
     * Sets match.account_id and match.opponent_id (once — never overwrites an existing value),
     * writes games.local_instance/opp_instance, and upserts game_decks rows.
     * Does not touch game_player. Never touches on_play — that is owned by SyncGamePivots.
     *
     * @param  array<int, array<string, mixed>>  $players
     * @param  array<int, array<string, mixed>>  $playerDeck
     */
    private static function upsertParticipants(
        MtgoMatch $match,
        Game $game,
        array $players,
        array $parsedState,
        ?LogEvent $lastStateEvent,
        array $playerDeck,
        ?string $username,
    ): void {
        $matchUpdates = [];
        $gameUpdates = [];

        foreach ($players as $player) {
            $name = $player['Name'];
            $isYou = $username !== null && $name === $username;

            if ($isYou) {
                // Resolve account: prefer lookup by username, fall back to currentId.
                if ($match->account_id === null) {
                    $accountId = Account::where('username', $username)->first()?->id
                        ?? Account::currentId();
                    if ($accountId !== null) {
                        $matchUpdates['account_id'] = $accountId;
                    }
                }

                $gameUpdates['local_instance'] = $player['Id'];

                GameDeck::updateOrCreate(
                    ['game_id' => $game->id, 'is_opponent' => false],
                    ['deck_json' => self::buildLocalDeck($parsedState, $player, $playerDeck)],
                );
            } else {
                // Resolve opponent: firstOrCreate by username, then set match.opponent_id once.
                if ($match->opponent_id === null) {
                    $opponent = Opponent::firstOrCreate(['username' => $name]);
                    $matchUpdates['opponent_id'] = $opponent->id;
                }

                $gameUpdates['opp_instance'] = $player['Id'];

                GameDeck::updateOrCreate(
                    ['game_id' => $game->id, 'is_opponent' => true],
                    ['deck_json' => self::buildOpponentDeck($lastStateEvent, $player)],
                );
            }
        }

        if (! empty($matchUpdates)) {
            $match->fill($matchUpdates)->save();
        }

        if (! empty($gameUpdates)) {
            $game->fill($gameUpdates)->save();
        }
    }

    /**
     * @param  array<string, mixed>  $parsedState
     * @param  array<string, mixed>  $player
     * @param  array<int, array<string, mixed>>  $playerDeck
     * @return array<int, array{mtgo_id: int|string, quantity: int, sideboard: bool}>
     */
    private static function buildLocalDeck(array $parsedState, array $player, array $playerDeck): array
    {
        if (empty($playerDeck)) {
            return [];
        }

        $totalQuantities = [];
        foreach ($playerDeck as $card) {
            $catalogId = $card['CatalogId'];
            $totalQuantities[$catalogId] = ($totalQuantities[$catalogId] ?? 0) + $card['Quantity'];
        }

        $sideboardCounts = [];
        foreach ($parsedState['Cards'] ?? [] as $snapshotCard) {
            if ((int) $snapshotCard['Owner'] === (int) $player['Id'] && ($snapshotCard['Zone'] ?? '') === 'Sideboard') {
                $catalogId = $snapshotCard['CatalogID'];
                $sideboardCounts[$catalogId] = ($sideboardCounts[$catalogId] ?? 0) + 1;
            }
        }

        $deck = [];
        foreach ($totalQuantities as $catalogId => $total) {
            $sbQty = $sideboardCounts[$catalogId] ?? 0;
            $mbQty = $total - $sbQty;

            if ($mbQty > 0) {
                $deck[] = ['mtgo_id' => $catalogId, 'quantity' => $mbQty, 'sideboard' => false];
            }
            if ($sbQty > 0) {
                $deck[] = ['mtgo_id' => $catalogId, 'quantity' => $sbQty, 'sideboard' => true];
            }
        }

        return $deck;
    }

    /**
     * @return array<int, array{mtgo_id: int|string, quantity: int, sideboard: bool}>
     */
    private static function buildOpponentDeck(?LogEvent $lastStateEvent, array $player): array
    {
        if (! $lastStateEvent) {
            return [];
        }

        $lastParsedState = ExtractJson::run($lastStateEvent->raw_text)->first();

        return collect($lastParsedState ? ($lastParsedState['Cards'] ?? []) : [])
            ->filter(fn ($card) => $card['Owner'] == $player['Id'])
            ->groupBy('CatalogID')
            ->map(fn ($cards) => [
                'mtgo_id' => $cards[0]['CatalogID'],
                'quantity' => $cards->count(),
                'sideboard' => false,
            ])->values()->toArray();
    }

    /**
     * @param  Collection<int, LogEvent>  $gameStateEvents
     */
    private static function replaceTimeline(Game $game, Collection $gameStateEvents): void
    {
        $events = [];
        $timelineCatalogIds = [];

        foreach ($gameStateEvents as $event) {
            $content = ExtractJson::run($event->raw_text)->first();

            if (! $content) {
                continue;
            }

            foreach ($content['Cards'] ?? [] as $card) {
                $timelineCatalogIds[] = $card['CatalogID'];
            }

            $events[] = [
                'game_id' => $game->id,
                'content' => json_encode($content),
                'timestamp' => $event->timestamp,
            ];
        }

        CreateMissingCards::run(array_unique($timelineCatalogIds));

        // Replace timeline entries — events may have grown since last call.
        // Non-critical: if the DB is locked by concurrent ingestion, skip
        // and let the next pass fill them in.
        try {
            GameTimeline::where('game_id', $game->id)->delete();
            GameTimeline::insert($events);
        } catch (QueryException $e) {
            Log::channel('pipeline')->info("CreateGames: timeline update skipped for game {$game->id}: {$e->getMessage()}");
        }
    }
}
