<?php

namespace App\Jobs;

use App\Actions\Pipeline\RunPipeline;
use App\Actions\Pipeline\RunWatchTick;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Backstop for the mtgo:watch daemon (the canonical ingestion path).
 *
 * Dispatched every 30 seconds; no-ops while the daemon heartbeat is fresh.
 * When the daemon is down (crash-restart gap, or contexts without the
 * Electron supervisor) this runs the full pipeline under the same
 * pipeline:tick lock the daemon uses, so the two hosts never overlap.
 *
 * uniqueFor must be >= timeout so the unique-lock TTL outlives the
 * worker's hard kill.
 */
class RunPipelineJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Heartbeat younger than this (seconds) means the daemon is alive. */
    public const HEARTBEAT_FRESH_SECONDS = 5;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue('pipeline');
    }

    public function uniqueId(): string
    {
        return 'pipeline:run';
    }

    public function handle(): void
    {
        if (static::daemonHeartbeatFresh()) {
            return;
        }

        // TTL assumes a run stays under 30 s; a cold-start backlog drain can
        // overrun it, letting a daemon tick overlap — tolerable because the
        // pipeline is idempotent (mirrors RunWatchTick's lock tradeoff).
        $lock = Cache::lock(RunWatchTick::LOCK_KEY, 30);

        if (! $lock->get()) {
            return;
        }

        try {
            RunPipeline::run();
        } finally {
            $lock->release();
        }
    }

    public static function daemonHeartbeatFresh(): bool
    {
        $heartbeat = Cache::get(RunWatchTick::HEARTBEAT_KEY);

        return $heartbeat !== null
            && (now()->timestamp - (int) $heartbeat) < self::HEARTBEAT_FRESH_SECONDS;
    }
}
