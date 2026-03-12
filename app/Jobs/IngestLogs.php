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
        $paths = FindMtgoLogPath::all();

        if ($paths->isEmpty()) {
            Cache::forget('mtgo.all_log_paths');
            $paths = FindMtgoLogPath::all();
        }

        foreach ($paths as $path) {
            if ($path && is_file($path)) {
                IngestLog::run($path);
            }
        }
    }
}
