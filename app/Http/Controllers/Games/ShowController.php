<?php

namespace App\Http\Controllers\Games;

use App\Data\Front\GameTimelineData;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Game;
use App\Models\GameTimeline;
use Inertia\Inertia;

class ShowController extends Controller
{
    public function __invoke(string $id)
    {
        $game = Game::findOrFail($id);
        $timeline = GameTimeline::where('game_id', $id)->get();

        // Batch load all cards referenced in the timeline
        $allCatalogIds = $timeline->flatMap(
            fn ($event) => collect($event->content['Cards'] ?? [])->pluck('CatalogID')
        )->unique();

        $cardsByMtgoId = Card::whereIn('mtgo_id', $allCatalogIds)->get()->keyBy('mtgo_id');

        $localPlayer = $game->localPlayers->first();

        $events = $timeline->map(function ($event) use ($cardsByMtgoId, $localPlayer) {
            $content = $event->content;

            foreach ($content['Players'] as $i => $player) {
                $content['Players'][$i]['IsLocal'] = $localPlayer?->username === $player['Name'];
            }

            foreach ($content['Cards'] as $i => $card) {
                $cardModel = $cardsByMtgoId->get($card['CatalogID']);
                $content['Cards'][$i]['image'] = $cardModel?->image;
                $content['Cards'][$i]['type'] = $cardModel?->type;
                $content['Cards'][$i]['name'] = $cardModel?->name;
            }

            return new GameTimelineData(
                timestamp: $event->timestamp,
                content: $content,
            );
        });

        return Inertia::render('games/Show', [
            'game' => $game,
            'timeline' => GameTimelineData::collect($events),
        ]);
    }
}
