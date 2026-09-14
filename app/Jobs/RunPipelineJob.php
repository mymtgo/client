<?php

namespace App\Jobs;

use App\Actions\Pipeline\RunPipeline;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Background dispatch of the unified pipeline tick.
 *
 * Dispatched every second. ShouldBeUnique drops duplicate dispatches
 * while a tick is still in flight so a long-running tick (backlog drain,
 * SQLite contention) cannot stack overlapping pipeline runs. The
 * scheduler call itself is sub-millisecond, which keeps the NativePHP
 * 60-second scheduler restart from deadlocking the pipeline.
 *
 * uniqueFor must be >= timeout so a hung tick cannot double-run, but it
 * is also the longest the pipeline can go dark: a worker killed mid-tick
 * (OOM, fatal) never releases the lock, and the store is the database
 * cache, so the row survives the restart and every dispatch until it
 * expires is silently dropped. IngestLogInstance::MAX_BYTES_PER_TICK
 * bounds a healthy tick to seconds, so a minute is generous here where
 * the old 300 was five minutes of silence.
 */
class RunPipelineJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $uniqueFor = 60;

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
        RunPipeline::run();
    }
}
