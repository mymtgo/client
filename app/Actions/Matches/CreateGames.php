<?php

namespace App\Actions\Matches;

use App\Actions\Cards\CreateMissingCards;
use App\Actions\Logs\ConvertMtgoTimestamp;
use App\Actions\Util\ExtractJson;
use App\Facades\Mtgo;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\GameTimeline;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Models\Player;
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

        // Read structured results directly from the stored GameLog
        $gameLog = null;
        $storedLog = GameLog::where('match_token', $match->token)->first();
        $candidates = ($storedLog && ! empty($storedLog->decoded_entries))
            ? ExtractGameResults::detectPlayers($storedLog->decoded_entries)
            : [];

        $username = Mtgo::resolveUsername($candidates);

        if ($storedLog && ! empty($storedLog->decoded_entries) && $username) {
            $gameLog = ExtractGameResults::run($storedLog->decoded_entries, $username);
        }

        $firstStateEvent = $gameStateEvents->first();
        $lastStateEvent = $gameStateEvents->last();

        // Always create the game record, even with incomplete data
        $gameModel = Game::where('mtgo_id', $gameId)->firstOrCreate([
            'match_id' => $match->id,
            'mtgo_id' => $gameId,
        ], [
            'won' => $gameLog['results'][$gameIndex] ?? null,
            'started_at' => $firstStateEvent
                ? ConvertMtgoTimestamp::run($firstStateEvent->logged_at, $firstStateEvent->timestamp)
                : null,
            'ended_at' => null,
        ]);

        // Update fields that may have been unavailable at creation time
        $gameModel->update([
            'won' => $gameLog['results'][$gameIndex] ?? $gameModel->won,
        ]);

        // If we have no state events yet, the game record exists for later backfill
        if (! $firstStateEvent) {
            Log::channel('pipeline')->info("CreateGames: no state events yet for game {$gameId} in match {$match->mtgo_id}");

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

        $playerModelMapping = [];

        foreach ($players as $player) {
            $playerModel = Player::where('username', $player['Name'])->firstOrCreate([
                'username' => $player['Name'],
            ]);
            $deck = [];

            $isYou = $playerModel->username == $username;

            if ($isYou && ! empty($playerDeck)) {
                // Build total quantities per CatalogId from DeckUsedInGame (the full 75)
                $totalQuantities = [];
                foreach ($playerDeck as $card) {
                    $catalogId = $card['CatalogId'];
                    $totalQuantities[$catalogId] = ($totalQuantities[$catalogId] ?? 0) + $card['Quantity'];
                }

                // Count actual sideboard cards from first game snapshot
                $sideboardCounts = [];
                foreach ($parsedState['Cards'] ?? [] as $snapshotCard) {
                    if ((int) $snapshotCard['Owner'] === (int) $player['Id'] && ($snapshotCard['Zone'] ?? '') === 'Sideboard') {
                        $catalogId = $snapshotCard['CatalogID'];
                        $sideboardCounts[$catalogId] = ($sideboardCounts[$catalogId] ?? 0) + 1;
                    }
                }

                // Build deck_json with sideboard flags reflecting actual game state
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
            }

            if (! $isYou) {
                $lastParsedState = ExtractJson::run($lastStateEvent->raw_text)->first();

                $deck = collect($lastParsedState ? ($lastParsedState['Cards'] ?? []) : [])
                    ->filter(fn ($card) => $card['Owner'] == $player['Id'])
                    ->groupBy('CatalogID')
                    ->map(function ($cards) {
                        return [
                            'mtgo_id' => $cards[0]['CatalogID'],
                            'quantity' => $cards->count(),
                            'sideboard' => false,
                        ];
                    })->values()->toArray();
            }

            $onPlay = $gameLog['on_play'][$gameIndex] ?? false;

            $playerModelMapping[$playerModel->id] = [
                'instance_id' => $player['Id'],
                'on_play' => ($onPlay && $isYou) || (! $onPlay && ! $isYou),
                'is_local' => $isYou,
                'deck_json' => $deck,
            ];
        }

        $gameModel->players()->sync($playerModelMapping);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: game {$gameId} — ".($gameModel->wasRecentlyCreated ? 'created' : 'updated').", {$gameModel->players()->count()} players synced");

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
                'game_id' => $gameModel->id,
                'content' => json_encode($content),
                'timestamp' => $event->timestamp,
            ];
        }

        CreateMissingCards::run(array_unique($timelineCatalogIds));

        // Replace timeline entries — events may have grown since last call.
        // Non-critical: if the DB is locked by concurrent ingestion, skip
        // and let the next pass fill them in.
        try {
            GameTimeline::where('game_id', $gameModel->id)->delete();
            GameTimeline::insert($events);
        } catch (QueryException $e) {
            Log::channel('pipeline')->info("CreateGames: timeline update skipped for game {$gameModel->id}: {$e->getMessage()}");
        }
    }
}
