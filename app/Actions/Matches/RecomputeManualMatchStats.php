<?php

namespace App\Actions\Matches;

use App\Jobs\ComputeCardGameStats;
use App\Models\MtgoMatch;

class RecomputeManualMatchStats
{
    /**
     * Rebuild card stats after a manual match's game detail changes. Tracked
     * and imported matches are left alone: their stats come from the log.
     */
    public static function run(MtgoMatch $match): void
    {
        if (! $match->manual) {
            return;
        }

        ComputeCardGameStats::dispatchSync($match->id);
    }
}
