<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetDeckStats;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Data\Front\ArchetypeData;
use App\Data\Front\MatchData;
use App\Enums\MatchOutcome;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Deck;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MatchesController extends Controller
{
    public function __invoke(Deck $deck, Request $request)
    {
        $shared = GetDeckViewSharedProps::run($deck);

        $from = now()->subMonths(2)->startOfDay();
        $to = now()->endOfDay();

        $stats = GetDeckStats::run($deck, $from, $to);
        $allMatchIds = $stats['allMatchIds'];

        // Build filtered match query
        $query = $deck->matches()->select('matches.*')->where('state', 'complete');

        if ($filterFrom = $request->input('filter_from')) {
            $query->where('started_at', '>=', Carbon::parse($filterFrom)->startOfDay());
        }
        if ($filterTo = $request->input('filter_to')) {
            $query->where('started_at', '<=', Carbon::parse($filterTo)->endOfDay());
        }
        if ($result = $request->input('filter_result')) {
            if ($result === 'win') {
                $query->where('outcome', MatchOutcome::Win);
            } elseif ($result === 'loss') {
                $query->where('outcome', MatchOutcome::Loss);
            }
        }
        if ($type = $request->input('filter_type')) {
            if ($type === 'league') {
                $query->whereNotNull('league_id');
            } elseif ($type === 'casual') {
                $query->whereNull('league_id');
            }
        }
        if ($archetype = $request->input('filter_archetype')) {
            $query->whereHas('opponentArchetypes', fn ($q) => $q->where('archetype_id', $archetype));
        }

        $matches = MatchData::collect(
            $query->with(['games.players', 'opponentArchetypes.archetype', 'opponentArchetypes.player', 'league'])
                ->orderByDesc('started_at')
                ->paginate(50)
        );

        $archetypes = Archetype::query()
            ->whereHas('matchArchetypes', fn ($q) => $q->whereIn('mtgo_match_id', $allMatchIds))
            ->withCount(['matchArchetypes' => fn ($q) => $q->whereIn('mtgo_match_id', $allMatchIds)])
            ->orderByDesc('match_archetypes_count')
            ->get()
            ->map(fn (Archetype $a) => [
                ...ArchetypeData::from($a)->toArray(),
                'matchCount' => $a->match_archetypes_count,
            ]);

        return Inertia::render('decks/Matches', [
            ...$shared,
            'currentPage' => 'matches',
            'matches' => $matches,
            'archetypes' => $archetypes,
        ]);
    }
}
