<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The pipeline's hot path queries (ProcessMatchEvents::run,
 * AdvanceMatchState::run) filter log_events on match_token, match_id,
 * and processed_at. With ~400k rows on real installs and no covering
 * index, each every-2s pipeline tick was running full table scans,
 * holding the SQLite write lock long enough to time out queue workers
 * and stall match creation entirely.
 *
 * Some single-column indexes already existed on installs that ran the
 * original create migration and were carried through schema swaps, but
 * not on every install — the index_exists guard makes this idempotent
 * either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_events', function (Blueprint $table) {
            if (! $this->indexExists('log_events_match_token_index')) {
                $table->index('match_token');
            }

            if (! $this->indexExists('log_events_match_id_index')) {
                $table->index('match_id');
            }

            // Covering index for the ProcessMatchEvents discovery query.
            // Column order chosen so SQLite can answer the query directly
            // from the index — measured 1850x speedup on a 400k-row table
            // (3.7s → 0.002s).
            if (! $this->indexExists('log_events_pipeline_discovery_index')) {
                $table->index(
                    ['event_type', 'processed_at', 'match_token', 'match_id'],
                    'log_events_pipeline_discovery_index',
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('log_events', function (Blueprint $table) {
            if ($this->indexExists('log_events_pipeline_discovery_index')) {
                $table->dropIndex('log_events_pipeline_discovery_index');
            }
        });
    }

    private function indexExists(string $indexName): bool
    {
        $indexes = DB::select('PRAGMA index_list(log_events)');

        return collect($indexes)->contains('name', $indexName);
    }
};
