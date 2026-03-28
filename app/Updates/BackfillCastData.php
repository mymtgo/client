<?php

namespace App\Updates;

use App\Jobs\ComputeCardGameStats;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillCastData extends AppUpdate
{
    public function version(): string
    {
        return '0.9.4';
    }

    public function run(): void
    {
        // Clear existing stats so they get recomputed with cast data
        DB::table('card_game_stats')->delete();

        $matchIds = MtgoMatch::query()
            ->where('state', 'complete')
            ->whereNotNull('deck_version_id')
            ->pluck('id');

        foreach ($matchIds as $matchId) {
            ComputeCardGameStats::dispatch($matchId);
        }

        Log::info("BackfillCastData: dispatched {$matchIds->count()} jobs");
    }
}
