<?php

namespace App\Jobs;

use App\Enums\MatchState;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class BackfillCardGameStats implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $matches = MtgoMatch::where('state', MatchState::Complete)
            ->whereNotNull('deck_version_id')
            ->whereHas('games', fn ($q) => $q->whereNotNull('won'))
            ->pluck('id');

        Log::channel('pipeline')->info("BackfillCardGameStats: processing {$matches->count()} completed matches");

        foreach ($matches as $matchId) {
            try {
                (new ComputeCardGameStats($matchId))->handle();
            } catch (\Throwable $e) {
                Log::channel('pipeline')->warning("BackfillCardGameStats: failed for match {$matchId}: {$e->getMessage()}");
            }
        }

        Log::channel('pipeline')->info('BackfillCardGameStats: complete');
    }
}
