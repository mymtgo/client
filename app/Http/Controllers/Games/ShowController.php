<?php

namespace App\Http\Controllers\Games;

use App\Data\Front\GameTimelineData;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Game;
use App\Models\GameTimeline;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShowController extends Controller
{
    public function __invoke(string $id, Request $request)
    {
        $game = Game::find($id);

        $timeline = GameTimeline::where('game_id', $id)->get();

        $cards = [];

        foreach ($timeline as $event) {
            $eventCards = $event->content['Cards'];
            foreach ($eventCards as $eventCard) {
                $cards[] = $eventCard['CatalogID'];
            }
        }

        $cardIds = collect($cards)->unique();

        $cardModels = Card::whereIn('mtgo_id', $cardIds)->get();

        $events = [];

        $localPlayer = $game->localPlayers->first();

        foreach ($timeline as $eIndex => $event) {
            $content = $event->content;

            foreach ($content['Players'] as $playerIndex => $player) {
                $content['Players'][$playerIndex]['IsLocal'] = $localPlayer->username == $player['Name'];
            }

            foreach ($content['Cards'] as $cardIndex => $eventCard) {
                $cardModel = $cardModels->first(
                    fn ($m) => $m->mtgo_id == $eventCard['CatalogID']
                );

                $content['Cards'][$cardIndex]['image'] = $cardModel?->image;
            }

            $events[] = new GameTimelineData(
                timestamp: $event->timestamp,
                content: $content
            );
        }

        return Inertia::render('games/Show', [
            'game' => $game,
            'timeline' => GameTimelineData::collect($events),
        ]);
    }
}
