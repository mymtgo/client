<?php

namespace App\Actions\Upgrade;

use App\Models\MtgoMatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropLegacyParticipantSchema
{
    /**
     * The final, irreversible step of the 1v1 participant backfill: drop the
     * legacy participant schema once the new schema has been fully populated.
     *
     * Order of operations:
     *  1. Safety guard — if game_player still exists, abort (throw) if any match
     *     still carries legacy game_player data but was never backfilled
     *     (account_id IS NULL). The guard query joins game_player so it only runs
     *     while that table is present. Throwing here propagates up through
     *     RunParticipantBackfill, which marks the tracker failed and leaves the
     *     legacy tables intact.
     *  2. Add the new UNIQUE(mtgo_match_id, is_opponent) index (idempotent).
     *  3. Drop the dead match_archetypes.player_id column + its FK + its index,
     *     each guarded by a presence check so partial retries converge.
     *  4. Drop the vestigial games.starting_hand_size column if present.
     *  5. Drop game_player and players (dropIfExists — unconditionally idempotent).
     *
     * Every step is independently idempotent: a crash at any point leaves a state
     * that a retry can complete without error.
     */
    public static function run(): void
    {
        // 1. Safety guard — refuse to drop if game_player still exists and
        //    the backfill is incomplete. This query joins game_player, so it
        //    must only run while that table is still present.
        if (Schema::hasTable('game_player')) {
            $unmigrated = MtgoMatch::query()
                ->whereNull('account_id')
                ->whereExists(function ($q) {
                    $q->from('games')
                        ->join('game_player', 'game_player.game_id', '=', 'games.id')
                        ->whereColumn('games.match_id', 'matches.id');
                })
                ->count();

            if ($unmigrated > 0) {
                throw new \RuntimeException(
                    "Refusing to drop legacy schema: {$unmigrated} match(es) have game_player data but no account_id — backfill incomplete."
                );
            }
        }

        // 2. Enforce one archetype row per side per match. Safe now: backfill
        //    set is_opponent and there is at most one row per (match, side).
        //    Idempotent: ensureMatchArchetypeUniqueIndex() checks before adding.
        self::ensureMatchArchetypeUniqueIndex();

        // 3. Drop the dead player_id column (and its FK + index) from match_archetypes.
        //
        //    On SQLite, dropping the column alone fails — the table rebuild
        //    re-emits the foreign key and the index, both referencing the
        //    now-missing column ("unknown column player_id ..."). The FK and
        //    the standalone index must be dropped first, each in its own
        //    statement, so the column drop sees a table with no dangling
        //    constraint or index.
        //
        //    Each sub-step is individually guarded so a crash after the FK drop
        //    but before the column drop leaves a retryable state.
        if (Schema::hasColumn('match_archetypes', 'player_id')) {
            // Check for a FK on player_id using getForeignKeys(), since SQLite
            // foreign keys have null names and do not appear in getIndexes().
            $hasForeignKey = collect(Schema::getForeignKeys('match_archetypes'))
                ->contains(fn ($fk) => in_array('player_id', $fk['columns'], true));

            if ($hasForeignKey) {
                Schema::table('match_archetypes', function (Blueprint $table) {
                    $table->dropForeign(['player_id']);
                });
            }

            // Re-fetch indexes after FK drop — SQLite rebuilds the table so the
            // index list may have changed.
            $existingIndexNames = collect(Schema::getIndexes('match_archetypes'))
                ->pluck('name')
                ->all();

            if (in_array('match_archetypes_player_id_index', $existingIndexNames, true)) {
                Schema::table('match_archetypes', function (Blueprint $table) {
                    $table->dropIndex(['player_id']);
                });
            }

            Schema::table('match_archetypes', function (Blueprint $table) {
                $table->dropColumn('player_id');
            });
        }

        // 4. Drop the vestigial games.starting_hand_size column if it exists.
        if (Schema::hasColumn('games', 'starting_hand_size')) {
            Schema::table('games', function (Blueprint $table) {
                $table->dropColumn('starting_hand_size');
            });
        }

        // 5. Drop the legacy participant tables. dropIfExists is unconditionally
        //    idempotent — safe whether game_player was already gone or not.
        Schema::dropIfExists('game_player');
        Schema::dropIfExists('players');
    }

    /**
     * Add a UNIQUE(mtgo_match_id, is_opponent) index to match_archetypes if it
     * is not already present. Idempotent: a duplicate add would throw, so we
     * guard on the existing index list first.
     */
    private static function ensureMatchArchetypeUniqueIndex(): void
    {
        $indexName = 'match_archetypes_mtgo_match_id_is_opponent_unique';

        $existing = collect(Schema::getIndexes('match_archetypes'))
            ->pluck('name')
            ->all();

        if (in_array($indexName, $existing, true)) {
            return;
        }

        Schema::table('match_archetypes', function (Blueprint $table) {
            $table->unique(['mtgo_match_id', 'is_opponent']);
        });
    }
}
