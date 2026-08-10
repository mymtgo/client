<?php

namespace App\Actions\Cards;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ApplyOpponentArchetypeFilter
{
    /**
     * Constrain a `card_game_stats as cgs` query to games played against a
     * given opponent archetype.
     *
     * Joins `games as g`, so callers must not join it again. The whereExists
     * confirms the matched archetype row belongs to the game's non-local
     * player rather than to the local player's own deck.
     */
    public static function to(Builder $query, int $archetypeId): void
    {
        $query->join('games as g', 'g.id', '=', 'cgs.game_id')
            ->join('match_archetypes as ma', function ($join) use ($archetypeId) {
                $join->on('ma.mtgo_match_id', '=', 'g.match_id')
                    ->where('ma.archetype_id', $archetypeId);
            })
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('game_player as gp')
                    ->whereRaw('gp.game_id = g.id')
                    ->whereRaw('gp.player_id = ma.player_id')
                    ->where('gp.is_local', false);
            });
    }
}
