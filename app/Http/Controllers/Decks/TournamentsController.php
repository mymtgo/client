<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetDeckViewSharedProps;
use App\Concerns\HasTimeframeFilter;
use App\Enums\TournamentState;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\MtgoMatch;
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

        $deckVersionIds = $deck->versions()->pluck('id');

        $linkedTournamentIds = MtgoMatch::query()
            ->whereIn('deck_version_id', $deckVersionIds)
            ->whereNotNull('tournament_id')
            ->distinct()
            ->pluck('tournament_id');

        $participatedTournamentIds = Tournament::query()
            ->whereIn('id', $linkedTournamentIds)
            ->where('participated', true)
            ->pluck('id');

        $completedFinishes = Tournament::query()
            ->whereIn('id', $participatedTournamentIds)
            ->where('state', TournamentState::Completed->value)
            ->get()
            ->map(fn (Tournament $tournament) => TournamentStanding::query()
                ->where('tournament_id', $tournament->id)
                ->where('is_local', true)
                ->orderByDesc('round')
                ->value('rank')
            )
            ->filter()
            ->values();

        $kpis = [
            'tournaments_played' => $participatedTournamentIds->count(),
            'best_finish' => $completedFinishes->min(),
            'top_8' => $completedFinishes->filter(fn ($r) => $r <= 8)->count(),
            'top_16' => $completedFinishes->filter(fn ($r) => $r <= 16)->count(),
        ];

        return Inertia::render('decks/Tournaments', [
            ...$shared,
            'currentPage' => 'tournaments',
            'timeframe' => $timeframe,
            'tournaments' => $tournaments,
            'localStandings' => $localStandings,
            'kpis' => $kpis,
        ]);
    }
}
