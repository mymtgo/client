<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add indexes identified by the 2026-03-24 codebase audit.
 *
 * The matches table had zero indexes despite the original migration
 * defining them — they were lost when the database was rebuilt.
 * This migration restores them and adds indexes for columns
 * introduced by later migrations (state, outcome, league_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        // matches — had zero indexes in existing installs
        Schema::table('matches', function (Blueprint $table) {
            // Skip indexes that already exist (idempotent for fresh installs)
            if (! $this->hasIndex('matches', 'matches_state_index')) {
                $table->index('state');
            }
            if (! $this->hasIndex('matches', 'matches_outcome_index')) {
                $table->index('outcome');
            }
            if (! $this->hasIndex('matches', 'matches_state_outcome_index')) {
                $table->index(['state', 'outcome']);
            }
            if (! $this->hasIndex('matches', 'matches_mtgo_id_index')) {
                $table->index('mtgo_id');
            }
            if (! $this->hasIndex('matches', 'matches_token_index')) {
                $table->index('token');
            }
            if (! $this->hasIndex('matches', 'matches_league_id_index')) {
                $table->index('league_id');
            }
            if (! $this->hasIndex('matches', 'matches_deck_version_id_index')) {
                $table->index('deck_version_id');
            }
            if (! $this->hasIndex('matches', 'matches_started_at_index')) {
                $table->index('started_at');
            }
        });

        // games — match_id FK had no index
        Schema::table('games', function (Blueprint $table) {
            if (! $this->hasIndex('games', 'games_match_id_index')) {
                $table->index('match_id');
            }
            if (! $this->hasIndex('games', 'games_match_id_started_at_index')) {
                $table->index(['match_id', 'started_at']);
            }
        });

        // leagues — state and started_at used frequently in filters/ordering
        Schema::table('leagues', function (Blueprint $table) {
            if (! $this->hasIndex('leagues', 'leagues_state_index')) {
                $table->index('state');
            }
            if (! $this->hasIndex('leagues', 'leagues_started_at_index')) {
                $table->index('started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex(['state']);
            $table->dropIndex(['outcome']);
            $table->dropIndex(['state', 'outcome']);
            $table->dropIndex(['mtgo_id']);
            $table->dropIndex(['token']);
            $table->dropIndex(['league_id']);
            $table->dropIndex(['deck_version_id']);
            $table->dropIndex(['started_at']);
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['match_id']);
            $table->dropIndex(['match_id', 'started_at']);
        });

        Schema::table('leagues', function (Blueprint $table) {
            $table->dropIndex(['state']);
            $table->dropIndex(['started_at']);
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = DB::select("PRAGMA index_list({$table})");

        return collect($indexes)->contains('name', $indexName);
    }
};
