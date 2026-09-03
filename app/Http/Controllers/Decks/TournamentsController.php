<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Archetypes\GetArchetypeOptions;
use App\Actions\Decks\GetDeckStats;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Actions\Tournaments\FormatTournamentRuns;
use App\Concerns\HasTimeframeFilter;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Tournament;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TournamentsController extends Controller
{
    use HasTimeframeFilter;

    public function __invoke(Request $request, Deck $deck)
    {
        $timeframe = $request->input('timeframe', 'alltime');
        [$from, $to] = $this->getTimeRange($timeframe);

        $shared = GetDeckViewSharedProps::run($deck, $from, $to);

        $deckVersion = $request->filled('version')
            ? DeckVersion::find($request->input('version'))
            : null;

        $stats = GetDeckStats::run($deck, $from, $to, $deckVersion);
        $allMatchIds = $stats['allMatchIds'];

        // Filter by when matches were played, not by tournament's scheduled
        // started_at — the latter comes from the API and can be a future
        // event date (e.g. a tournament that hasn't started yet but already
        // has matches recorded under a related event id).
        $tournaments = Tournament::query()
            ->whereHas('matches', fn ($q) => $q
                ->whereIn('matches.id', $allMatchIds)
                ->whereBetween('matches.started_at', [$from, $to]),
            )
            ->orderByDesc('started_at')
            ->get();

        $deck->loadMissing(['cover', 'versions']);

        return Inertia::render('decks/Tournaments', [
            ...$shared,
            'currentVersionId' => $deckVersion?->id,
            'currentPage' => 'tournaments',
            'timeframe' => $timeframe,
            'tournaments' => FormatTournamentRuns::run($tournaments, $deck),
            'archetypes' => Inertia::defer(fn () => GetArchetypeOptions::run()),
        ]);
    }
}
