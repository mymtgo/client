<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetDeckViewSharedProps;
use App\Concerns\HasTimeframeFilter;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\Tournament;
use App\Models\TournamentStanding;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TournamentsController extends Controller
{
    use HasTimeframeFilter;

    public function __invoke(Request $request, Deck $deck): Response
    {
        $timeframe = $request->input('timeframe', 'alltime');
        [$from, $to] = $this->getTimeRange($timeframe);

        $shared = GetDeckViewSharedProps::run($deck, $from, $to);

        $tournaments = Tournament::whereHas('matches', function ($q) use ($deck) {
            $q->whereHas('deckVersion', fn ($dv) => $dv->where('deck_id', $deck->id));
        })
            ->orderByDesc('started_at')
            ->get();

        $tournamentIds = $tournaments->pluck('id')->all();
        $localStandings = TournamentStanding::whereIn('tournament_id', $tournamentIds)
            ->where('is_local', true)
            ->orderByDesc('round')
            ->get()
            ->unique('tournament_id')
            ->keyBy('tournament_id');

        return Inertia::render('decks/Tournaments', [
            ...$shared,
            'currentPage' => 'tournaments',
            'timeframe' => $timeframe,
            'tournaments' => $tournaments,
            'localStandings' => $localStandings,
        ]);
    }
}
