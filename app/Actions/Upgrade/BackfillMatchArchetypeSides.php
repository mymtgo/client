<?php

namespace App\Actions\Upgrade;

use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;

class BackfillMatchArchetypeSides
{
    /**
     * Backfill `match_archetypes.is_opponent` for a single match from the
     * legacy `game_player` pivot.
     *
     * Only rows with a non-null `player_id` (legacy rows) are touched.
     * Post-Phase-2 rows with `player_id = null` are skipped entirely — their
     * `is_opponent` was set correctly at creation time by DetermineMatchArchetypes.
     *
     * Idempotent: re-running against an already-correct DB is a no-op because
     * the UPDATE only flips rows whose current flag differs from the derived value.
     */
    public static function run(MtgoMatch $match): void
    {
        $gameIds = $match->games()->pluck('id');

        if ($gameIds->isEmpty()) {
            return;
        }

        // Collect the distinct player_ids that appear in this match's games on
        // each side.  A player_id appearing with is_local=0 is the opponent;
        // is_local=1 is the local account.
        $rows = DB::table('game_player')
            ->whereIn('game_id', $gameIds)
            ->select(['player_id', 'is_local'])
            ->distinct()
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        // Build sets for fast look-up.
        $opponentPlayerIds = $rows->where('is_local', 0)->pluck('player_id')->unique()->values()->all();
        $localPlayerIds = $rows->where('is_local', 1)->pluck('player_id')->unique()->values()->all();

        if (empty($opponentPlayerIds) && empty($localPlayerIds)) {
            return;
        }

        // Set is_opponent=true for legacy rows linked to opponent players.
        if (! empty($opponentPlayerIds)) {
            DB::table('match_archetypes')
                ->where('mtgo_match_id', $match->id)
                ->whereNotNull('player_id')
                ->whereIn('player_id', $opponentPlayerIds)
                ->where('is_opponent', false)
                ->update(['is_opponent' => true]);
        }

        // Ensure legacy rows linked to local players remain is_opponent=false
        // (defensive: they default false, but guard in case a prior partial run
        // accidentally set them true).
        if (! empty($localPlayerIds)) {
            DB::table('match_archetypes')
                ->where('mtgo_match_id', $match->id)
                ->whereNotNull('player_id')
                ->whereIn('player_id', $localPlayerIds)
                ->where('is_opponent', true)
                ->update(['is_opponent' => false]);
        }
    }
}
