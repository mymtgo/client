<?php

namespace App\Actions\Matches;

use App\Actions\Cards\CreateMissingCards;
use App\Actions\Util\ExtractJson;
use App\Facades\Mtgo;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Support\Collection;

class CreateGames
{
    public static function run(MtgoMatch $match, int $gameId, Collection $gameEvents, int $gameIndex, array $playerDeck)
    {
        $username = Mtgo::getUsername();

        $gameStateEvents = $gameEvents->filter(
            fn (LogEvent $event) => $event->event_type == 'game_state_update'
        );
        $firstStateEvent = $gameStateEvents->first();
        $lastStateEvent = $gameStateEvents->last();
        $gameLog = GetGameLog::run($match->token);

        $players = ExtractJson::run($gameStateEvents->first()->raw_text)->first()['Players'];

        $gameModel = Game::where('mtgo_id', $gameId)->firstOrCreate([
            'match_id' => $match->id,
            'mtgo_id' => $gameId,
        ], [
            'won' => $gameLog['results'][$gameIndex] ?? null,
            'started_at' => now()->parse($firstStateEvent->logged_at)->setTimeFromTimeString($firstStateEvent->timestamp),
            'ended_at' => now()->parse($lastStateEvent->logged_at)->setTimeFromTimeString($lastStateEvent->timestamp),
        ]);

        $playerModels = collect();
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
                $deck = collect(
                    ExtractJson::run(
                        $gameStateEvents->last()->raw_text
                    )->first()['Cards'] ?? []
                )->filter(
                    fn ($card) => $card['Owner'] == $player['Id']
                )->groupBy('CatalogID')->map(function ($cards) {

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

            $playerModels->push($playerModel);
        }

        $gameModel->players()->sync($playerModelMapping);

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
        GameTimeline::insert($events);
    }
}
