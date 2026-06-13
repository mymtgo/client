<?php

namespace App\Actions\Pipeline;

use App\Actions\Logs\FindMtgoLogPath;
use Illuminate\Support\Facades\Cache;

class RunWatchTick
{
    public const HEARTBEAT_KEY = 'pipeline:daemon_heartbeat';

    public const LOCK_KEY = 'pipeline:tick';

    /** Seconds between heartbeat cache writes — far below the backstop's 5 s freshness window, far above the tick cadence. */
    private const HEARTBEAT_WRITE_INTERVAL_SECONDS = 2;

    private static ?float $lastHeartbeatWroteAt = null;

    /**
     * Run one watcher tick: refresh the heartbeat, detect log growth via
     * stat(), and run the ingestion hot path when anything changed.
     *
     * Exceptions from the hot path propagate to the caller (the daemon loop
     * owns catch/log/continue); the pipeline lock is always released.
     *
     * @param  array<string, int>  $lastSizes  path => size observed on the previous tick
     * @return array<string, int> sizes observed this tick (feed into the next call)
     */
    public static function run(array $lastSizes): array
    {
        static::writeHeartbeat();

        if (! app('mtgo')->canRun()) {
            return $lastSizes;
        }

        $sizes = [];
        $changed = false;

        foreach (FindMtgoLogPath::all() as $path) {
            // PHP caches stat results — mandatory in a long-running process,
            // otherwise filesize() reports the boot-time size forever.
            clearstatcache(true, $path);
            $size = @filesize($path);

            if ($size === false) {
                continue;
            }

            $sizes[$path] = $size;

            if (($lastSizes[$path] ?? -1) !== $size) {
                $changed = true;
            }
        }

        if (! $changed) {
            return $sizes;
        }

        // TTL assumes a hot-path run stays under 30 s; steady-state deltas are tiny, and the pipeline is idempotent if a cold-start catch-up overruns the lock.
        $lock = Cache::lock(self::LOCK_KEY, 30);

        if (! $lock->get()) {
            // Backstop holds the lock — return $lastSizes unchanged so the
            // growth is re-detected on the next tick.
            return $lastSizes;
        }

        try {
            RunPipeline::hotPath();
        } finally {
            $lock->release();
        }

        return $sizes;
    }

    public static function resetHeartbeatThrottle(): void
    {
        static::$lastHeartbeatWroteAt = null;
    }

    /**
     * Refresh the daemon heartbeat, throttled in-memory so the resident
     * daemon's ~150 ms tick does not write to the contended SQLite cache
     * store on every iteration (single-writer strategy, see
     * config/nativephp.php queue_workers note).
     */
    private static function writeHeartbeat(): void
    {
        $now = microtime(true);

        if (
            static::$lastHeartbeatWroteAt !== null
            && ($now - static::$lastHeartbeatWroteAt) < self::HEARTBEAT_WRITE_INTERVAL_SECONDS
        ) {
            return;
        }

        static::$lastHeartbeatWroteAt = $now;
        Cache::put(self::HEARTBEAT_KEY, now()->timestamp, 30);
    }
}
