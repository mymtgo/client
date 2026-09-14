<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class OpponentMatchPairs
{
    /**
     * Distinct match/player pairs for every player who appeared as the
     * non-local player in at least one game of that match.
     *
     * Join this against a matches/match_archetypes query to keep only the
     * archetype rows whose player actually played that match as the opponent.
     * It replaces a correlated `EXISTS` that SQLite re-evaluated once per
     * candidate row, which made the enclosing query scale linearly with match
     * count. Built once and joined, the cost tracks the size of `game_player`
     * instead of the number of matches on screen.
     */
    public static function query(): QueryBuilder
    {
        return DB::table('game_player as gp')
            ->join('games as g', 'g.id', '=', 'gp.game_id')
            ->where('gp.is_local', false)
            ->distinct()
            ->select('g.match_id as match_id', 'gp.player_id as player_id');
    }

    /**
     * Join condition pairing the subquery to a match and its archetype row.
     *
     * The pair is unique per match and player, so the join cannot duplicate
     * rows the way a non-distinct join would.
     */
    public static function on(string $matches = 'm', string $archetypes = 'ma', string $as = 'opp'): Closure
    {
        return function (JoinClause $join) use ($matches, $archetypes, $as) {
            $join->on("{$as}.match_id", '=', "{$matches}.id")
                ->on("{$as}.player_id", '=', "{$archetypes}.player_id");
        };
    }
}
