<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A snapshot arriving through sync can reference a match this device
     * has not imported yet, so it remembers the match's token; the match
     * importer re-links match_id when that match lands. Backfilled from the
     * matches table for rows written by the local pipeline.
     */
    public function up(): void
    {
        Schema::table('limited_deck_snapshots', function (Blueprint $table) {
            $table->string('match_token')->nullable()->index();
        });

        DB::statement(<<<'SQL'
            UPDATE limited_deck_snapshots
            SET match_token = (SELECT token FROM matches WHERE matches.id = limited_deck_snapshots.match_id)
            WHERE match_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('limited_deck_snapshots', function (Blueprint $table) {
            $table->dropColumn('match_token');
        });
    }
};
