<?php

namespace App\Updates;

use App\Jobs\BackfillCardGameStats;

class BackfillCastData extends AppUpdate
{
    public function version(): string
    {
        return '0.9.4';
    }

    public function run(): void
    {
        BackfillCardGameStats::dispatch();
    }
}
