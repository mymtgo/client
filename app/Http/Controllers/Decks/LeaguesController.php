<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetDeckStats;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Actions\Leagues\FormatLeagueRuns;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\League;
use Inertia\Inertia;

class LeaguesController extends Controller
{
    public function __invoke(Deck $deck)
    {
        $shared = GetDeckViewSharedProps::run($deck);

        $from = now()->subMonths(2)->startOfDay();
        $to = now()->endOfDay();

        $stats = GetDeckStats::run($deck, $from, $to);
        $allMatchIds = $stats['allMatchIds'];

        $leagues = League::whereHas('matches', fn ($q) => $q->whereIn('matches.id', $allMatchIds))
            ->with(['deckVersion.deck'])
            ->orderByDesc('started_at')
            ->get();

        return Inertia::render('decks/Leagues', [
            ...$shared,
            'currentPage' => 'leagues',
            'leagues' => FormatLeagueRuns::run($leagues, deckId: $deck->id),
        ]);
    }
}
