<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetDeckStats;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Actions\Leagues\FormatLeagueRuns;
use App\Concerns\HasTimeframeFilter;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeaguesController extends Controller
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

        $leagues = League::whereHas('matches', fn ($q) => $q->whereIn('matches.id', $allMatchIds))
            ->with(['deckVersion.deck'])
            ->orderByDesc('started_at')
            ->get();

        return Inertia::render('decks/Leagues', [
            ...$shared,
            'currentVersionId' => $deckVersion?->id,
            'currentPage' => 'leagues',
            'timeframe' => $timeframe,
            'leagues' => FormatLeagueRuns::run($leagues, deckId: $deck->id),
        ]);
    }
}
