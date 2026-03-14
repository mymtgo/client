<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('log_events') || ! Schema::hasTable('matches')) {
            return;
        }

        // A scoping bug in AdvanceMatchState caused orWhere conditions to
        // leak across matches, marking unrelated log events as processed.
        // Reset processed_at for events that reference a match_id or
        // match_token not present in the matches table, so BuildMatches
        // can re-discover and process them.
        DB::statement('
            UPDATE log_events
            SET processed_at = NULL
            WHERE processed_at IS NOT NULL
            AND (
                (match_id IS NOT NULL AND match_id NOT IN (SELECT mtgo_id FROM matches))
                OR
                (match_token IS NOT NULL AND match_token NOT IN (SELECT token FROM matches))
            )
        ');
    }
};
