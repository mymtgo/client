<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the MTGO helper's raw capture. Data players can see (replay
     * frames in game_timelines, the game_player clock columns, results,
     * decks and leagues) is kept.
     *
     * The tables go first: game_events.log_instance_id cascades, so deleting
     * the helper's log_instances rows while game_events exists would delete
     * its rows one at a time at boot.
     */
    public function up(): void
    {
        Schema::dropIfExists('game_field_diffs');
        Schema::dropIfExists('game_events');

        // MTGO's own logs are .log files, so this only matches the helper's
        // NDJSON event files.
        $helperInstanceIds = DB::table('log_instances')
            ->where('file_path', 'like', '%events-%.ndjson')
            ->pluck('id');

        if ($helperInstanceIds->isNotEmpty()) {
            DB::table('log_cursors')->whereIn('log_instance_id', $helperInstanceIds)->delete();
            DB::table('log_instances')->whereIn('id', $helperInstanceIds)->delete();
        }

        if (Schema::hasColumn('games', 'timeline_source')) {
            Schema::table('games', function (Blueprint $table) {
                $table->dropColumn('timeline_source');
            });
        }

        DB::table('jobs')->where('queue', 'sidecar')->delete();
    }

    public function down(): void {}
};
