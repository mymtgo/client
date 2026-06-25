<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetDeckStats;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Concerns\HasTimeframeFilter;
use App\Data\Front\ArchetypeData;
use App\Data\Front\MatchData;
use App\Enums\MatchOutcome;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MatchesController extends Controller
{
    use HasTimeframeFilter;

    public function __invoke(Deck $deck, Request $request)
    {
        $timeframe = $request->input('timeframe', 'alltime');
        [$from, $to] = $this->getTimeRange($timeframe);

        $shared = GetDeckViewSharedProps::run($deck, $from, $to);

        $deckVersion = $request->filled('version')
            ? DeckVersion::find($request->input('version'))
            : null;

        $stats = GetDeckStats::run($deck, $from, $to, $deckVersion);
        $allMatchIds = $stats['allMatchIds'];

        // Build filtered match query
        $query = $deck->matches()->select('matches.*')->where('state', 'complete')
            ->when($deckVersion, fn ($q) => $q->where('deck_version_id', $deckVersion->id))
            ->whereBetween('started_at', [$from, $to]);

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
            $query->filteredByArchetype($archetype);
        }

        $sortColumn = $request->input('sort', 'started_at');
        $sortDir = $request->input('sort_dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $directSorts = ['started_at', 'outcome', 'games_won', 'games_lost'];

        if ($sortColumn === 'archetype') {
            $query->leftJoin('match_archetypes as ma_sort', function ($join) {
                $join->on('ma_sort.mtgo_match_id', '=', 'matches.id')
                    ->where('ma_sort.is_opponent', true);
            })
                ->leftJoin('archetypes as a_sort', 'a_sort.id', '=', 'ma_sort.archetype_id')
                ->orderBy('a_sort.name', $sortDir);
        } elseif ($sortColumn === 'duration') {
            $query->orderByRaw("(julianday(ended_at) - julianday(started_at)) {$sortDir}");
        } elseif (preg_match('/^game_([123])$/', $sortColumn, $m)) {
            $gameIndex = (int) $m[1] - 1;
            $query->orderByRaw("(
                SELECT g_sort.won FROM games g_sort
                WHERE g_sort.match_id = matches.id
                ORDER BY g_sort.started_at
                LIMIT 1 OFFSET {$gameIndex}
            ) {$sortDir}");
        } elseif (in_array($sortColumn, $directSorts)) {
            $query->orderBy($sortColumn, $sortDir);
        } else {
            $query->orderByDesc('started_at');
        }

        $matches = MatchData::collect(
            $query->with(['games', 'opponentArchetypes.archetype', 'opponent', 'league'])
                ->withCount([
                    'games as games_won_count' => fn ($q) => $q->where('won', true),
                    'games as games_lost_count' => fn ($q) => $q->where('won', false),
                ])
                ->paginate(50)
        );

        // Map internal format code to archetype format
        $formatMap = [
            'CMODERN' => 'modern',
            'CPAUPER' => 'pauper',
            'CLEGACY' => 'legacy',
            'CVINTAGE' => 'vintage',
            'CPREMODERN' => 'premodern',
            'CPIONEER' => 'pioneer',
        ];
        $archetypeFormat = $formatMap[$deck->format] ?? strtolower($deck->format);

        $unknownArchetypeCount = $deck->matches()
            ->where('state', 'complete')
            ->whereIn('matches.id', $allMatchIds)
            ->whereDoesntHave('opponentArchetypes')
            ->count();

        $pendingArchetypeCount = $deck->matches()
            ->whereNotNull('archetype_detection_queued_at')
            ->count();

        return Inertia::render('decks/Matches', [
            ...$shared,
            'currentVersionId' => $deckVersion?->id,
            'currentPage' => 'matches',
            'timeframe' => $timeframe,
            'matches' => $matches,
            'unknownArchetypeCount' => $unknownArchetypeCount,
            'pendingArchetypeCount' => $pendingArchetypeCount,

            // Deferred — heavy filter dropdown data (per-archetype match counts)
            'archetypes' => Inertia::defer(fn () => Archetype::query()
                ->forFormat($archetypeFormat)
                ->withCount(['matchArchetypes' => fn ($q) => $q
                    ->whereIn('mtgo_match_id', $allMatchIds)
                    ->where('is_opponent', true),
                ])
                ->orderByDesc('match_archetypes_count')
                ->orderBy('name')
                ->get()
                ->map(fn (Archetype $a) => [
                    ...ArchetypeData::from($a)->toArray(),
                    'matchCount' => $a->match_archetypes_count,
                ])),
        ]);
    }
}
