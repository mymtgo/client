<?php

namespace App\Jobs;

use App\Actions\Logs\FindMtgoLogPath;
use App\Actions\Logs\IngestLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class IngestLogs implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $path = FindMtgoLogPath::run();

        if (! $path || ! is_file($path)) {
            Cache::forget('mtgo.active_log_path');
            $path = FindMtgoLogPath::run();
        }

        IngestLog::run($path);
    }
}
