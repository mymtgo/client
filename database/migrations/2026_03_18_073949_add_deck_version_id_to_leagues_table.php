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
            $table->foreignId('deck_version_id')->nullable()->after('state')->constrained('deck_versions')->nullOnDelete();
        });

        // Backfill: set each league's deck_version_id from its first match.
        // Note: existing multi-run leagues are NOT retroactively split —
        // they are tagged with the first run's deck version. Only future
        // matches will create properly split leagues.
        $leagues = DB::table('leagues')->whereNull('deck_version_id')->pluck('id');

        foreach ($leagues as $leagueId) {
            $deckVersionId = DB::table('matches')
                ->where('league_id', $leagueId)
                ->whereNotNull('deck_version_id')
                ->whereNull('deleted_at')
                ->orderBy('started_at')
                ->value('deck_version_id');

            if ($deckVersionId) {
                DB::table('leagues')
                    ->where('id', $leagueId)
                    ->update(['deck_version_id' => $deckVersionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deck_version_id');
        });
    }
};
