<?php

namespace App\Actions\Upgrade;

use App\Models\MtgoMatch;
use App\Models\SchemaUpgrade;
use Illuminate\Support\Facades\Schema;

class RunParticipantBackfill
{
    /**
     * Run the full participant backfill across all stages, with optional
     * progress tracking via a SchemaUpgrade record.
     *
     * Stages:
     *  1. "participants"  — populate account_id/opponent_id/game scalars/game_decks
     *  2. "archetypes"    — set match_archetypes.is_opponent from legacy player_id
     *  3. "cleanup"       — synthesize gameless import games + delete orphans
     *  4. "finalize"      — drop the legacy participant schema (destructive)
     *
     * The action is idempotent: each sub-action skips already-populated rows, and
     * the legacy-reading stages (1 + 2) are skipped entirely once the legacy
     * `game_player` table has been dropped by a prior run. Re-running against a
     * fully-backfilled (and finalized) database is a safe no-op.
     *
     * On any exception the tracker (if provided) is marked failed and the
     * exception is rethrown so the caller can surface it.
     */
    public static function run(?SchemaUpgrade $tracker = null): void
    {
        try {
            // Stages 1 + 2 read the legacy game_player/players tables. Once the
            // finalize stage has dropped them, those stages have nothing to do
            // and must not query the missing tables.
            $legacySchemaPresent = Schema::hasTable('game_player');

            if ($legacySchemaPresent) {
                // --- Stage 1: participants ---
                $total = MtgoMatch::query()->count();
                $tracker?->markStage('participants', $total);

                MtgoMatch::query()->chunkById(200, function ($matches) use ($tracker) {
                    foreach ($matches as $match) {
                        BackfillMatchParticipants::run($match);
                        $tracker?->increment('progress');
                    }
                });

                // --- Stage 2: archetypes ---
                $total = MtgoMatch::query()->count();
                $tracker?->markStage('archetypes', $total);

                MtgoMatch::query()->chunkById(200, function ($matches) use ($tracker) {
                    foreach ($matches as $match) {
                        BackfillMatchArchetypeSides::run($match);
                        $tracker?->increment('progress');
                    }
                });
            }

            // --- Stage 3: cleanup ---
            $tracker?->markStage('cleanup', 1);
            SynthesizeGamelessImportGames::run();
            $tracker?->increment('progress');

            // --- Stage 4: finalize (destructive — drop legacy participant schema) ---
            $tracker?->markStage('finalize', 1);
            DropLegacyParticipantSchema::run();
            $tracker?->increment('progress');

            $tracker?->markComplete();
        } catch (\Throwable $e) {
            $tracker?->markFailed($e->getMessage());
            throw $e;
        }
    }
}
