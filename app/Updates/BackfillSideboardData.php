<?php

namespace App\Updates;

use App\Jobs\BackfillDeckJsonAndCardStats;

class BackfillSideboardData extends AppUpdate
{
    public function version(): string
    {
        return '0.9.5';
    }

    public function run(): void
    {
        BackfillDeckJsonAndCardStats::dispatch();
    }
}
