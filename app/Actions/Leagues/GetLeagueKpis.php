<?php

namespace App\Actions\Leagues;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GetLeagueKpis
{
    /**
     * @return array{
     *     runs: array{total: int, completed: int, live: int, decks: int},
     *     trophies: int,
     *     trophyRate: float|null,
     *     cashRate: float|null,
     *     avgFinish: float|null,
     *     topMatchup: array{archetype: string, wins: int, losses: int, count: int}|null
     * }
     */
    public static function run(Builder $leaguesQuery): array
    {
        $leagueIds = (clone $leaguesQuery)->pluck('id');

        if ($leagueIds->isEmpty()) {
            return self::empty();
        }

        $stateById = (clone $leaguesQuery)->pluck('state', 'id');

        /** Trophy threshold is the league's own round count: 3-0 for a draft. */
        $kindById = (clone $leaguesQuery)->pluck('kind', 'id');

        $matchOutcomes = DB::table('matches')
            ->whereIn('league_id', $leagueIds)
            ->where('state', 'complete')
            ->select('league_id', 'outcome')
            ->get()
            ->groupBy('league_id');

        $completedWins = collect();
        $completed = 0;
        $live = 0;
        $trophies = 0;

        foreach ($leagueIds as $id) {
            $rows = $matchOutcomes->get($id, collect());
            $wins = $rows->where('outcome', 'win')->count();
            $state = $stateById[$id]?->value ?? null;

            if ($state === 'complete' || $state === 'dropped') {
                $completed++;
                $completedWins->push($wins);
                if ($wins >= ($kindById[$id]?->roundCount() ?? 5)) {
                    $trophies++;
                }
            } elseif ($state === 'active') {
                $live++;
            }
        }

        $deckCount = DB::table('matches as m')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->whereIn('m.league_id', $leagueIds)
            ->distinct()
            ->count('dv.deck_id');

        $cash = $completedWins->filter(fn ($w) => $w >= 4)->count();

        $trophyRate = $completed > 0 ? round(($trophies / $completed) * 100, 0) : null;
        $cashRate = $completed > 0 ? round(($cash / $completed) * 100, 0) : null;
        $avgFinish = $completed > 0 ? round($completedWins->avg(), 1) : null;

        return [
            'runs' => [
                'total' => $leagueIds->count(),
                'completed' => $completed,
                'live' => $live,
                'decks' => $deckCount,
            ],
            'trophies' => $trophies,
            'trophyRate' => $trophyRate,
            'cashRate' => $cashRate,
            'avgFinish' => $avgFinish,
            'topMatchup' => self::topMatchup($leagueIds),
        ];
    }

    /**
     * @return array{archetype: string, wins: int, losses: int, count: int}|null
     */
    private static function topMatchup(Collection $leagueIds): ?array
    {
        $matchIds = DB::table('matches')
            ->whereIn('league_id', $leagueIds)
            ->where('state', 'complete')
            ->pluck('id');

        if ($matchIds->isEmpty()) {
            return null;
        }

        $rows = DB::table('match_archetypes as ma')
            ->join('matches as m', 'm.id', '=', 'ma.mtgo_match_id')
            ->join('archetypes as a', 'a.id', '=', 'ma.archetype_id')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('game_player as gp')
                    ->join('games as g', 'g.id', '=', 'gp.game_id')
                    ->whereRaw('g.match_id = ma.mtgo_match_id')
                    ->whereRaw('gp.player_id = ma.player_id')
                    ->where('gp.is_local', false);
            })
            ->whereIn('ma.mtgo_match_id', $matchIds)
            ->select('a.name', 'm.outcome')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->groupBy('name')
            ->map(fn ($g, $name) => [
                'archetype' => $name,
                'wins' => $g->where('outcome', 'win')->count(),
                'losses' => $g->where('outcome', 'loss')->count(),
                'count' => $g->count(),
            ])
            ->sortByDesc(fn ($r) => $r['count'] * 1000 + ($r['count'] > 0 ? ($r['wins'] / $r['count']) : 0))
            ->values()
            ->first();
    }

    /**
     * @return array{
     *     runs: array{total: int, completed: int, live: int, decks: int},
     *     trophies: int,
     *     trophyRate: null,
     *     cashRate: null,
     *     avgFinish: null,
     *     topMatchup: null
     * }
     */
    private static function empty(): array
    {
        return [
            'runs' => ['total' => 0, 'completed' => 0, 'live' => 0, 'decks' => 0],
            'trophies' => 0,
            'trophyRate' => null,
            'cashRate' => null,
            'avgFinish' => null,
            'topMatchup' => null,
        ];
    }
}
