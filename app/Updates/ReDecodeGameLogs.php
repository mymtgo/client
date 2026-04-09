<?php

namespace App\Updates;

use App\Jobs\ReDecodeGameLogsJob;

class ReDecodeGameLogs extends AppUpdate
{
    public function run(): void
    {
        ReDecodeGameLogsJob::dispatch();
    }
}
