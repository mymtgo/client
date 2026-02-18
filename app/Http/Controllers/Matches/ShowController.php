<?php

namespace App\Http\Controllers\Matches;

use App\Data\Front\CardData;
use App\Data\Front\MatchData;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShowController extends Controller
{
    public function __invoke(string $id, Request $request)
    {
        // TODO: wire up real data
        // $match = MtgoMatch::with(['deck', 'games.players', 'games.timeline'])->find($id);
        // $cardsSeen = collect();
        // foreach ($match->games as $game) {
        //     foreach ($game->players as $player) {
        //         $cardsSeen->push(collect($player->pivot->deck_json)->pluck('mtgo_id'));
        //     }
        // }
        // $cards = Card::whereIn('mtgo_id', $cardsSeen->flatten()->unique())->get();
        // return Inertia::render('matches/Show', [
        //     'cards' => CardData::collect($cards),
        //     'match' => MatchData::from($match),
        // ]);

        return Inertia::render('matches/Show');
    }
}
