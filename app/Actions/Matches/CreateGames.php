<?php

namespace App\Actions\Matches;

use App\Actions\Cards\CreateMissingCards;
use App\Actions\Util\ExtractJson;
use App\Models\Account;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CreateGames
{
    public static function run(MtgoMatch $match, int $gameId, Collection $gameEvents, int $gameIndex, array $playerDeck)
    {
        $username = $match->games()->first()?->localPlayers()?->first()?->username
            ?? LogEvent::where('match_token', $match->token)->whereNotNull('username')->value('username')
            ?? Account::active()->value('username');

        $gameStateEvents = $gameEvents->filter(
            fn (LogEvent $event) => $event->event_type == 'game_state_update'
        );
        $gameLog = GetGameLog::run($match->token);

        $firstStateEvent = $gameStateEvents->first();
        $lastStateEvent = $gameStateEvents->last();

        // Always create the game record, even with incomplete data
        $gameModel = Game::where('mtgo_id', $gameId)->firstOrCreate([
            'match_id' => $match->id,
            'mtgo_id' => $gameId,
        ], [
            'won' => $gameLog['results'][$gameIndex] ?? null,
            'started_at' => $firstStateEvent
                ? now()->parse($firstStateEvent->logged_at)->setTimeFromTimeString($firstStateEvent->timestamp)
                : null,
            'ended_at' => $lastStateEvent
                ? now()->parse($lastStateEvent->logged_at)->setTimeFromTimeString($lastStateEvent->timestamp)
                : null,
        ]);

        // Update fields that may have been unavailable at creation time
        $gameModel->update([
            'won' => $gameLog['results'][$gameIndex] ?? $gameModel->won,
            'ended_at' => $lastStateEvent
                ? now()->parse($lastStateEvent->logged_at)->setTimeFromTimeString($lastStateEvent->timestamp)
                : $gameModel->ended_at,
        ]);

        // If we have no state events yet, the game record exists for later backfill
        if (! $firstStateEvent) {
            Log::channel('pipeline')->info("CreateGames: no state events yet for game {$gameId} in match {$match->mtgo_id}");

            return;
        }

        $parsedState = ExtractJson::run($firstStateEvent->raw_text)->first();
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

            if ($isYou) {
                $deck = collect($playerDeck)->map(function ($card) {
                    return [
                        'mtgo_id' => $card['CatalogId'],
                        'quantity' => $card['Quantity'],
                        'sideboard' => $card['InSideboard'],
                    ];
                })->values()->toArray();
            }

            if (! $isYou) {
                $lastParsedState = ExtractJson::run($lastStateEvent->raw_text)->first();

                $deck = collect($lastParsedState['Cards'] ?? [])
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
        } catch (\Illuminate\Database\QueryException $e) {
            Log::channel('pipeline')->info("CreateGames: timeline update skipped for game {$gameModel->id}: {$e->getMessage()}");
        }
    }
}
