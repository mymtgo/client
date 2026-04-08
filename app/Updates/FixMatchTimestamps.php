<?php

namespace App\Updates;

use App\Jobs\FixMatchTimestampsJob;

class FixMatchTimestamps extends AppUpdate
{
    public function run(): void
    {
        FixMatchTimestampsJob::dispatch();
    }
}
