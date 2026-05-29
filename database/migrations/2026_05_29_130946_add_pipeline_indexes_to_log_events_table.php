<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pipeline's hot path queries (ProcessMatchEvents::run,
 * AdvanceMatchState::run) filter log_events on match_token, match_id,
 * and processed_at. With ~400k rows on real installs and no index on
 * any of those columns, each every-2s pipeline tick was running full
 * table scans, holding the SQLite write lock long enough to time out
 * queue workers and stall match creation entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_events', function (Blueprint $table) {
            // Per-column indexes for the AdvanceMatchState lookups
            // (by match_id, by match_token) which fan out from the
            // discovery query.
            $table->index('match_token');
            $table->index('match_id');

            // Covering index for the ProcessMatchEvents discovery query.
            // Column order chosen so SQLite can answer the query directly
            // from the index — measured 1850x speedup on a 400k-row table
            // (3.7s → 0.002s).
            $table->index(
                ['event_type', 'processed_at', 'match_token', 'match_id'],
                'log_events_pipeline_discovery_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('log_events', function (Blueprint $table) {
            $table->dropIndex('log_events_pipeline_discovery_index');
            $table->dropIndex(['match_id']);
            $table->dropIndex(['match_token']);
        });
    }
};
