<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->boolean('deck_change_detected')->default(false)->after('phantom');
        });

        // Retroactively set phantom = true for leagues that were created as phantom
        // (identified by having a randomly-generated token, i.e. no real MTGO league token)
        // MTGO league tokens follow a specific pattern; random ones from Str::random() are alphanumeric 16 chars.
        // Simpler: all existing leagues with "Phantom" in the name were phantom runs.
        DB::table('leagues')
            ->where('name', 'like', 'Phantom%')
            ->update(['phantom' => true]);

        // Flag phantom leagues that contain matches from more than one distinct deck.
        $mixedLeagueIds = DB::table('leagues as l')
            ->join('matches as m', 'm.league_id', '=', 'l.id')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->where('l.phantom', true)
            ->whereNull('m.deleted_at')
            ->select('l.id')
            ->groupBy('l.id')
            ->havingRaw('COUNT(DISTINCT dv.deck_id) > 1')
            ->pluck('l.id');

        if ($mixedLeagueIds->isNotEmpty()) {
            DB::table('leagues')
                ->whereIn('id', $mixedLeagueIds)
                ->update(['deck_change_detected' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->dropColumn('deck_change_detected');
        });

        // Revert phantom flag (set all back to 0 as that was the previous state)
        DB::table('leagues')->where('name', 'like', 'Phantom%')->update(['phantom' => false]);
    }
};
