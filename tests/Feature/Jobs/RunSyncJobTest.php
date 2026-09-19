<?php

use App\Jobs\RunSyncJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

it('keys the unique id to the mode so incremental and full runs do not collide', function () {
    expect((new RunSyncJob(full: false))->uniqueId())->toBe('run-sync:incremental')
        ->and((new RunSyncJob(full: true))->uniqueId())->toBe('run-sync:full');
});

it('implements ShouldBeUnique so duplicate dispatches are suppressed', function () {
    expect(new RunSyncJob)->toBeInstanceOf(ShouldBeUnique::class);
});

it('dedupes two dispatches sharing the same mode', function () {
    Bus::fake();

    Cache::clear();

    RunSyncJob::dispatch(full: false);
    RunSyncJob::dispatch(full: false);

    Bus::assertDispatchedTimes(RunSyncJob::class, 1);
});

it('lets a full and an incremental dispatch queue independently', function () {
    Bus::fake();

    Cache::clear();

    RunSyncJob::dispatch(full: false);
    RunSyncJob::dispatch(full: true);

    Bus::assertDispatchedTimes(RunSyncJob::class, 2);
});
