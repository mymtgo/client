<?php

namespace App\Http\Controllers\Challenges;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowController extends Controller
{
    public function __invoke(Request $request, Challenge $challenge): Response
    {
        $latestRound = $challenge->standings()->max('round') ?? 0;

        $standingsByRound = $challenge->standings()
            ->orderBy('rank')
            ->get()
            ->groupBy('round')
            ->sortKeys();

        $rounds = $standingsByRound->keys()->sort()->values()->all();

        $timelineEvents = $challenge->timelineEvents()
            ->orderByDesc('occurred_at')
            ->get();

        $eliminatedIds = $challenge->timelineEvents()
            ->where('event_type', 'player_eliminated')
            ->pluck('login_id')
            ->filter()
            ->all();

        return Inertia::render('challenges/Show', [
            'challenge' => $challenge,
            'standingsByRound' => $standingsByRound,
            'rounds' => $rounds,
            'timelineEvents' => $timelineEvents,
            'eliminatedIds' => $eliminatedIds,
            'latestRound' => $latestRound,
            'fromDeck' => $request->input('deck_id'),
        ]);
    }
}
