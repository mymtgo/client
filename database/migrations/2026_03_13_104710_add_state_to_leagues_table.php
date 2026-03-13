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
            $table->string('state')->default('active')->after('deck_change_detected');
        });

        // Backfill: mark leagues with 5+ complete matches as complete
        $completeLeagueIds = DB::table('leagues')
            ->whereNull('deleted_at')
            ->whereIn('id', function ($query) {
                $query->select('league_id')
                    ->from('matches')
                    ->where('state', 'complete')
                    ->whereNull('deleted_at')
                    ->groupBy('league_id')
                    ->havingRaw('COUNT(*) >= 5');
            })
            ->pluck('id');

        if ($completeLeagueIds->isNotEmpty()) {
            DB::table('leagues')
                ->whereIn('id', $completeLeagueIds)
                ->update(['state' => 'complete']);
        }

        // Backfill: mark real leagues with < 5 matches where a newer real
        // league exists in the same format as partial (phantoms don't get
        // partial — they're matched by deck, not format token)
        $activeRealLeagues = DB::table('leagues')
            ->where('phantom', false)
            ->where('state', 'active')
            ->whereNull('deleted_at')
            ->get(['id', 'format', 'started_at']);

        foreach ($activeRealLeagues as $league) {
            $newerExists = DB::table('leagues')
                ->where('format', $league->format)
                ->where('phantom', false)
                ->where('started_at', '>', $league->started_at)
                ->whereNull('deleted_at')
                ->exists();

            if ($newerExists) {
                DB::table('leagues')
                    ->where('id', $league->id)
                    ->update(['state' => 'partial']);
            }
        }
    }

    public function down(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->dropColumn('state');
        });
    }
};
