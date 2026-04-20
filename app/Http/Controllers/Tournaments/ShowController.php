<?php

namespace App\Http\Controllers\Tournaments;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowController extends Controller
{
    public function __invoke(Request $request, Tournament $tournament): Response
    {
        $latestRound = $tournament->standings()->max('round') ?? 0;

        $standingsByRound = $tournament->standings()
            ->orderBy('rank')
            ->get()
            ->groupBy('round')
            ->sortKeys();

        $rounds = $standingsByRound->keys()->sort()->values()->all();

        $timelineEvents = $tournament->timelineEvents()
            ->orderByDesc('occurred_at')
            ->get();

        $eliminatedIds = $tournament->timelineEvents()
            ->where('event_type', 'player_eliminated')
            ->pluck('login_id')
            ->filter()
            ->all();

        return Inertia::render('tournaments/Show', [
            'tournament' => $tournament,
            'standingsByRound' => $standingsByRound,
            'rounds' => $rounds,
            'timelineEvents' => $timelineEvents,
            'eliminatedIds' => $eliminatedIds,
            'latestRound' => $latestRound,
            'fromDeck' => $request->input('deck_id'),
        ]);
    }
}
