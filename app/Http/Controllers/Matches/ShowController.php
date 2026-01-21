<?php

namespace App\Http\Controllers\Matches;

use App\Data\Front\MatchData;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShowController extends Controller
{
    public function __invoke(string $id, Request $request)
    {
        $match = MtgoMatch::find($id);

        $player = $match->games;

        dd($player);

        return Inertia::render('matches/Show', [
            'match' => MatchData::from($match),
        ]);
    }
}
