<?php

namespace App\Http\Controllers\Games;

use App\Data\Front\MatchData;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShowController extends Controller
{
    public function __invoke(string $id, Request $request)
    {
        $game = Game::find($id);

        return Inertia::render('games/Game', [
            'game' => $game,
        ]);
    }
}
