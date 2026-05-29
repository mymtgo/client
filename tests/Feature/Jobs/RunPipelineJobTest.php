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
