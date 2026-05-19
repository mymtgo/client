<?php

namespace App\Updates;

use App\Actions\Cards\EnqueueCardStats;

class BackfillCardStatsShipQueue extends AppUpdate
{
    public function run(): void
    {
        do {
            $inserted = EnqueueCardStats::run(limit: 500);
        } while ($inserted > 0);
    }
}
