<?php

namespace App\Updates;

use App\Jobs\BackfillCardGameStats;

class BackfillCastData extends AppUpdate
{
    public function run(): void
    {
        BackfillCardGameStats::dispatch();
    }
}
