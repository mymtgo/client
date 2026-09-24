<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which source owns the game's game_timelines rows: 'log' (Twitch
     * snapshots from mtgo.log) or 'sidecar' (frames folded from
     * game_events). Null on rows written before this column existed and
     * is read as 'log'. See docs/superpowers/specs/2026-09-23-sidecar-replay-timeline-design.md.
     */
    public function up(): void
    {
        if (Schema::hasColumn('games', 'timeline_source')) {
            return;
        }

        Schema::table('games', function (Blueprint $table) {
            $table->string('timeline_source', 16)->nullable()->after('turn_count');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('timeline_source');
        });
    }
};
