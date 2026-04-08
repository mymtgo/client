<?php

namespace App\Updates;

use App\Jobs\FixMatchTimestampsJob;

class FixMatchTimestampsV2 extends AppUpdate
{
    public function run(): void
    {
        FixMatchTimestampsJob::dispatch();
    }
}
