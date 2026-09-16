<?php

namespace App\Actions\Matches;

use App\Data\Front\ArchetypeData;
use App\Data\Front\MatchData;
use App\Enums\MatchOutcome;
use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\MtgoMatch;
use App\Support\OpponentMatchPairs;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The filterable, sortable match list shared by the per-deck Matches page and
 * the archetype Matches tab. Callers hand in the already-scoped base query
 * (which decks, which timeframe); this applies the user's list filters and
 * sort, paginates, and builds the archetype filter dropdown for the same set.
 */
class BuildMatchListProps
{
    /**
     * @param  Builder<MtgoMatch>|Relation<MtgoMatch, covariant \Illuminate\Database\Eloquent\Model, mixed>  $scoped  complete matches in scope, before list filters
     * @param  array<int, string>  $with  relations to eager load on the page of matches
     * @return array{matches: LengthAwarePaginator, unknownArchetypeCount: int, archetypes: mixed}
     */
    public static function run(Builder|Relation $scoped, Request $request, string $archetypeFormat, array $with = []): array
    {
        // Kept as a subquery, never plucked: a thousand bound ids in an IN list
        // costs SQLite seconds per statement, a subquery costs milliseconds.
        $scopedIds = (clone $scoped)->select('matches.id');

        $query = self::applyFilters(clone $scoped, $request);
        self::applySort($query, $request);

        $matches = MatchData::collect(
            $query->with(['games.players', 'opponentArchetypes.archetype', 'opponentArchetypes.player', 'league', ...$with])
                ->withCount([
                    'games as games_won_count' => fn ($q) => $q->where('won', true),
                    'games as games_lost_count' => fn ($q) => $q->where('won', false),
                ])
                ->paginate(50)
                ->withQueryString()
        );

        $unknownArchetypeCount = (clone $scoped)
            ->whereNotIn('matches.id', OpponentMatchPairs::identifiedMatchIds())
            ->count();

        return [
            'matches' => $matches,
            'unknownArchetypeCount' => $unknownArchetypeCount,
            // Deferred: per-archetype match counts are the heaviest part of the page.
            'archetypes' => Inertia::defer(fn () => self::archetypeOptions($archetypeFormat, $scopedIds)),
        ];
    }

    /**
     * @param  Builder<MtgoMatch>|Relation<MtgoMatch, covariant \Illuminate\Database\Eloquent\Model, mixed>  $query
     * @return Builder<MtgoMatch>|Relation<MtgoMatch, covariant \Illuminate\Database\Eloquent\Model, mixed>
     */
    protected static function applyFilters(Builder|Relation $query, Request $request): Builder|Relation
    {
        $timezone = AppSettings::systemTimezone();

        if ($filterFrom = $request->input('filter_from')) {
            $query->where('started_at', '>=', Carbon::parse($filterFrom, $timezone)->startOfDay()->utc());
        }
        if ($filterTo = $request->input('filter_to')) {
            $query->where('started_at', '<=', Carbon::parse($filterTo, $timezone)->endOfDay()->utc());
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
            } elseif ($type === 'manual') {
                $query->where('manual', true);
            }
        }
        if ($archetype = $request->input('filter_archetype')) {
            $query->filteredByArchetype($archetype);
        }

        return $query;
    }

    /**
     * @param  Builder<MtgoMatch>|Relation<MtgoMatch, covariant \Illuminate\Database\Eloquent\Model, mixed>  $query
     */
    protected static function applySort(Builder|Relation $query, Request $request): void
    {
        $sortColumn = $request->input('sort', 'started_at');
        $sortDir = $request->input('sort_dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $directSorts = ['started_at', 'outcome', 'games_won', 'games_lost'];

        if ($sortColumn === 'archetype') {
            // One derived table of opponent archetype names per match. A
            // correlated subquery here re-ran once per candidate row.
            $opponentNames = DB::table('match_archetypes as ma')
                ->join('archetypes as a', 'a.id', '=', 'ma.archetype_id')
                ->joinSub(OpponentMatchPairs::query(), 'opp', OpponentMatchPairs::on('ma', 'ma', 'opp'))
                ->groupBy('ma.mtgo_match_id')
                ->select('ma.mtgo_match_id', DB::raw('MIN(a.name) as name'));

            $query->leftJoinSub($opponentNames, 'a_sort', 'a_sort.mtgo_match_id', '=', 'matches.id')
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
    }

    /**
     * Archetypes of the scoped format with how many of the scoped matches
     * were against each, for the filter dropdown.
     *
     * @param  Builder<MtgoMatch>|Relation<MtgoMatch, covariant \Illuminate\Database\Eloquent\Model, mixed>  $scopedIds  selects `matches.id`
     */
    protected static function archetypeOptions(string $archetypeFormat, Builder|Relation $scopedIds): Collection
    {
        $counts = DB::table('match_archetypes as ma')
            ->joinSub(OpponentMatchPairs::query(), 'opp', OpponentMatchPairs::on('ma', 'ma', 'opp'))
            ->whereIn('ma.mtgo_match_id', $scopedIds->getQuery())
            ->groupBy('ma.archetype_id')
            ->select('ma.archetype_id', DB::raw('COUNT(*) as match_count'));

        return Archetype::query()
            ->forFormat($archetypeFormat)
            ->leftJoinSub($counts, 'mc', 'mc.archetype_id', '=', 'archetypes.id')
            ->select('archetypes.*', DB::raw('COALESCE(mc.match_count, 0) as match_archetypes_count'))
            ->withExists('decks')
            ->orderByDesc('match_archetypes_count')
            ->orderBy('name')
            ->get()
            ->map(fn (Archetype $a) => [
                ...ArchetypeData::from($a)->toArray(),
                'matchCount' => (int) $a->match_archetypes_count,
            ]);
    }
}
