<?php

namespace App\Jobs;

use App\Actions\Pipeline\RunPipeline;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Background dispatch of the unified pipeline tick.
 *
 * Dispatched on a 2-second schedule. ShouldBeUnique drops duplicate
 * dispatches while a tick is still in flight so a long-running tick
 * (backlog drain, SQLite contention) cannot stack overlapping pipeline
 * runs. The scheduler call itself is sub-millisecond, which keeps the
 * NativePHP 60-second scheduler restart from deadlocking the pipeline.
 *
 * uniqueFor must be >= timeout so the unique-lock TTL outlives the
 * worker's hard kill: if the worker terminates a hung job, the lock
 * has not yet expired and a fresh dispatch can land cleanly when it
 * does.
 */
class RunPipelineJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

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
        RunPipeline::run();
    }
}
