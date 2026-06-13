<?php

namespace App\Console\Commands;

use App\Actions\Pipeline\IsTransientWriteError;
use App\Actions\Pipeline\RunPipeline;
use App\Actions\Pipeline\RunWatchTick;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WatchMtgoLogs extends Command
{
    protected $signature = 'mtgo:watch {--once : Run a single tick and exit (testing)}';

    protected $description = 'Resident watcher: stat-polls MTGO logs and runs the ingestion hot path on change';

    /** Recycle the process past this RSS so PHP state never grows unbounded. */
    private const MEMORY_CEILING_BYTES = 512 * 1024 * 1024;

    /** Recycle the process after this many hours of uptime. */
    private const UPTIME_CEILING_HOURS = 6;

    /** Seconds between maintenance-phase runs. */
    private const MAINTENANCE_INTERVAL_SECONDS = 30;

    public function handle(): int
    {
        $intervalMs = (int) config('mtgo.watch_interval_ms', 150);
        $startedAt = now();
        $lastMaintenanceAt = 0.0;
        $sizes = [];

        Log::channel('pipeline')->info('mtgo:watch started', ['interval_ms' => $intervalMs]);

        while (true) {
            $tickStart = microtime(true);

            try {
                $sizes = RunWatchTick::run($sizes);

                if ($tickStart - $lastMaintenanceAt >= self::MAINTENANCE_INTERVAL_SECONDS) {
                    $lastMaintenanceAt = $tickStart;
                    $this->runMaintenance();
                }
            } catch (\Throwable $e) {
                if (IsTransientWriteError::run($e)) {
                    Log::channel('pipeline')->info('mtgo:watch transient error, continuing', [
                        'error' => $e->getMessage(),
                    ]);
                } else {
                    Log::channel('pipeline')->error('mtgo:watch crashed', [
                        'error' => $e->getMessage(),
                        'file' => $e->getFile().':'.$e->getLine(),
                    ]);

                    // Non-zero exit → NativePHP persistent ChildProcess restarts us.
                    return self::FAILURE;
                }
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            if ($this->shouldRecycle($startedAt)) {
                Log::channel('pipeline')->info('mtgo:watch recycling (memory/uptime ceiling)');

                return self::SUCCESS;
            }

            $elapsedMs = (microtime(true) - $tickStart) * 1000;
            usleep((int) (max(0, $intervalMs - $elapsedMs) * 1000));
        }
    }

    private function runMaintenance(): void
    {
        $lock = Cache::lock(RunWatchTick::LOCK_KEY, 60);

        if (! $lock->get()) {
            return;
        }

        try {
            if (app('mtgo')->canRun()) {
                RunPipeline::maintenance();
            }
        } finally {
            $lock->release();
        }
    }

    private function shouldRecycle(Carbon $startedAt): bool
    {
        return memory_get_usage(true) > self::MEMORY_CEILING_BYTES
            || $startedAt->diffInHours(now()) >= self::UPTIME_CEILING_HOURS;
    }
}
