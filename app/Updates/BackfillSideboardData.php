<?php

namespace App\Updates;

use App\Jobs\BackfillDeckJsonAndCardStats;

class BackfillSideboardData extends AppUpdate
{
    public function run(): void
    {
        BackfillDeckJsonAndCardStats::dispatch();
    }
}
