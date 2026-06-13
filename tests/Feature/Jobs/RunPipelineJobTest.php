<?php

use App\Actions\Pipeline\RunWatchTick;
use App\Jobs\RunPipelineJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

it('exposes a stable unique id for the pipeline lock', function () {
    expect((new RunPipelineJob)->uniqueId())->toBe('pipeline:run');
});

it('implements ShouldBeUnique so duplicate dispatches are suppressed', function () {
    expect(new RunPipelineJob)->toBeInstanceOf(ShouldBeUnique::class);
});

it('skips duplicate dispatches while a pipeline tick is already in flight', function () {
    Bus::fake();

    RunPipelineJob::dispatch();
    RunPipelineJob::dispatch();
    RunPipelineJob::dispatch();

    Bus::assertDispatchedTimes(RunPipelineJob::class, 1);
});

it('treats a fresh daemon heartbeat as alive', function () {
    Cache::put(RunWatchTick::HEARTBEAT_KEY, now()->timestamp, 30);

    expect(RunPipelineJob::daemonHeartbeatFresh())->toBeTrue();
});

it('treats a stale heartbeat as dead', function () {
    Cache::put(RunWatchTick::HEARTBEAT_KEY, now()->subSeconds(10)->timestamp, 30);

    expect(RunPipelineJob::daemonHeartbeatFresh())->toBeFalse();
});

it('treats a missing heartbeat as dead', function () {
    expect(RunPipelineJob::daemonHeartbeatFresh())->toBeFalse();
});
