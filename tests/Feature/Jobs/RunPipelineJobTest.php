<?php

use App\Jobs\RunPipelineJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

it('exposes a stable unique id for the pipeline lock', function () {
    expect((new RunPipelineJob)->uniqueId())->toBe('pipeline:run');
});

it('implements ShouldBeUnique so duplicate dispatches are suppressed', function () {
    expect(new RunPipelineJob)->toBeInstanceOf(ShouldBeUnique::class);
});

it('skips duplicate dispatches while a pipeline tick is already in flight', function () {
    Bus::fake();

    Cache::clear();

    RunPipelineJob::dispatch();
    RunPipelineJob::dispatch();
    RunPipelineJob::dispatch();

    Bus::assertDispatchedTimes(RunPipelineJob::class, 1);
});

it('never holds the unique lock longer than a healthy tick could take', function () {
    // A worker killed mid-tick never releases the lock, so uniqueFor is the
    // longest the pipeline can go silent. It must outlive the job timeout
    // (so a hung tick cannot double-run) but stay far below the old 300s.
    $job = new RunPipelineJob;

    expect($job->uniqueFor)->toBeGreaterThanOrEqual($job->timeout)
        ->and($job->uniqueFor)->toBeLessThanOrEqual(60)
        ->and((int) config('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout);
});
