<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add missing FK, filter, and compound indexes identified during
 * the performance audit. Remove the deck_versions.signature index
 * which is no longer useful.
 */
return new class extends Migration
{
    public function up(): void
    {
        // deck_versions — FK index on deck_id
        Schema::table('deck_versions', function (Blueprint $table) {
            if (! $this->hasIndex('deck_versions', 'deck_versions_deck_id_index')) {
                $table->index('deck_id');
            }

            // Remove signature index — no longer useful
            if ($this->hasIndex('deck_versions', 'deck_versions_signature_index')) {
                $table->dropIndex('deck_versions_signature_index');
            }
        });

        // decks — FK indexes on account_id and archetype_id
        Schema::table('decks', function (Blueprint $table) {
            if (! $this->hasIndex('decks', 'decks_account_id_index')) {
                $table->index('account_id');
            }
            if (! $this->hasIndex('decks', 'decks_archetype_id_index')) {
                $table->index('archetype_id');
            }
        });

        // card_game_stats — FK index on game_id, compound on (deck_version_id, oracle_id)
        Schema::table('card_game_stats', function (Blueprint $table) {
            if (! $this->hasIndex('card_game_stats', 'card_game_stats_game_id_index')) {
                $table->index('game_id');
            }
            if (! $this->hasIndex('card_game_stats', 'card_game_stats_deck_version_id_oracle_id_index')) {
                $table->index(['deck_version_id', 'oracle_id']);
            }
        });

        // leagues — FK index on deck_version_id
        Schema::table('leagues', function (Blueprint $table) {
            if (! $this->hasIndex('leagues', 'leagues_deck_version_id_index')) {
                $table->index('deck_version_id');
            }
        });

        // import_scans — FK index on deck_version_id
        Schema::table('import_scans', function (Blueprint $table) {
            if (! $this->hasIndex('import_scans', 'import_scans_deck_version_id_index')) {
                $table->index('deck_version_id');
            }
        });

        // log_events — filter index on processed_at
        Schema::table('log_events', function (Blueprint $table) {
            if (! $this->hasIndex('log_events', 'log_events_processed_at_index')) {
                $table->index('processed_at');
            }
        });

        // matches — filter index on imported, compound on (state, started_at)
        Schema::table('matches', function (Blueprint $table) {
            if (! $this->hasIndex('matches', 'matches_imported_index')) {
                $table->index('imported');
            }
            if (! $this->hasIndex('matches', 'matches_state_started_at_index')) {
                $table->index(['state', 'started_at']);
            }
        });

        // games — compound on (match_id, won)
        Schema::table('games', function (Blueprint $table) {
            if (! $this->hasIndex('games', 'games_match_id_won_index')) {
                $table->index(['match_id', 'won']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('deck_versions', function (Blueprint $table) {
            $table->dropIndex(['deck_id']);
            $table->index('signature');
        });

        Schema::table('decks', function (Blueprint $table) {
            $table->dropIndex(['account_id']);
            $table->dropIndex(['archetype_id']);
        });

        Schema::table('card_game_stats', function (Blueprint $table) {
            $table->dropIndex(['game_id']);
            $table->dropIndex(['deck_version_id', 'oracle_id']);
        });

        Schema::table('leagues', function (Blueprint $table) {
            $table->dropIndex(['deck_version_id']);
        });

        Schema::table('import_scans', function (Blueprint $table) {
            $table->dropIndex(['deck_version_id']);
        });

        Schema::table('log_events', function (Blueprint $table) {
            $table->dropIndex(['processed_at']);
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex(['imported']);
            $table->dropIndex(['state', 'started_at']);
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['match_id', 'won']);
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = DB::select("PRAGMA index_list({$table})");

        return collect($indexes)->contains('name', $indexName);
    }
};
