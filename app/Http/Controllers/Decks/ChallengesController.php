<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetDeckViewSharedProps;
use App\Concerns\HasTimeframeFilter;
use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\ChallengeStanding;
use App\Models\Deck;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChallengesController extends Controller
{
    use HasTimeframeFilter;

    public function __invoke(Request $request, Deck $deck): Response
    {
        $timeframe = $request->input('timeframe', 'alltime');
        [$from, $to] = $this->getTimeRange($timeframe);

        $shared = GetDeckViewSharedProps::run($deck, $from, $to);

        $challenges = Challenge::whereHas('matches', function ($q) use ($deck) {
            $q->whereHas('deckVersion', fn ($dv) => $dv->where('deck_id', $deck->id));
        })
            ->orderByDesc('started_at')
            ->get();

        $challengeIds = $challenges->pluck('id')->all();
        $localStandings = ChallengeStanding::whereIn('challenge_id', $challengeIds)
            ->where('is_local', true)
            ->orderByDesc('round')
            ->get()
            ->unique('challenge_id')
            ->keyBy('challenge_id');

        return Inertia::render('decks/Challenges', [
            ...$shared,
            'currentPage' => 'challenges',
            'timeframe' => $timeframe,
            'challenges' => $challenges,
            'localStandings' => $localStandings,
        ]);
    }
}
