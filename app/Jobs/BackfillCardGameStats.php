<?php

namespace App\Jobs;

use App\Models\MtgoMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillCardGameStats implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        DB::table('card_game_stats')->delete();

        $matchIds = MtgoMatch::query()
            ->where('state', 'complete')
            ->whereNotNull('deck_version_id')
            ->pluck('id');

        foreach ($matchIds as $matchId) {
            ComputeCardGameStats::dispatch($matchId);
        }

        Log::info("BackfillCardGameStats: dispatched {$matchIds->count()} jobs");
    }
}
