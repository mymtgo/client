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

        $standings = $challenge->standings()
            ->where('round', $latestRound)
            ->orderBy('rank')
            ->get();

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
            'standings' => $standings,
            'timelineEvents' => $timelineEvents,
            'eliminatedIds' => $eliminatedIds,
            'latestRound' => $latestRound,
            'fromDeck' => $request->input('deck_id'),
        ]);
    }
}
