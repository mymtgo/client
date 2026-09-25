<?php

use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Jobs\RunSyncJob;
use App\Managers\MtgoManager;
use App\Models\MtgoMatch;
use App\Services\Sync\SyncTokens;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/**
 * `Schedule` is normally populated by the `withSchedule()` callback wired
 * up in bootstrap/app.php, but that callback only fires when Laravel's
 * console `Application` bootstraps, not merely by resolving `Schedule::class`
 * from the container. Registering `MtgoManager::schedule()` against a fresh
 * `Schedule` instance directly sidesteps that timing, mirroring the pattern
 * `SchedulerGatingTest` and `MtgoManagerScheduleTest` already use.
 */
function runSyncScheduledEvent(): Event
{
    $schedule = app(Schedule::class);

    if (empty($schedule->events())) {
        app(MtgoManager::class)->schedule($schedule);
    }

    return collect($schedule->events())
        ->first(fn ($event) => str_contains((string) $event->description, 'run_sync'))
        ?? throw new RuntimeException('No scheduled event matching run_sync');
}

it('registers the sync worker on its own sync queue', function () {
    expect(config('nativephp.queue_workers.sync.queues'))->toContain('sync');
});

it('schedules RunSyncJob every thirty minutes with an explicit overlap expiry', function () {
    $event = runSyncScheduledEvent();

    expect($event->expression)->toBe('*/30 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(120);
});

it('does not run the scheduled sync while the device is not linked', function () {
    $event = runSyncScheduledEvent();

    expect($event->filtersPass(app()))->toBeFalse();
});

it('runs the scheduled sync once the device is linked', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);

    $event = runSyncScheduledEvent();

    expect($event->filtersPass(app()))->toBeTrue();
});

it('does not run the scheduled sync while offline mode is on, even when linked', function () {
    // Without this gate, the job dispatches every half hour, builds every
    // dirty bundle, then throws OfflineModeException from the first HTTP
    // call inside SyncRunner: wasted work every schedule tick while the
    // user is offline, not merely a log line.
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    AppSettings::setOffline(true);

    $event = runSyncScheduledEvent();

    expect($event->filtersPass(app()))->toBeFalse();
});

it('schedules a sync when a match completes on a linked, online device', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    Bus::fake([RunSyncJob::class]);

    $match = MtgoMatch::factory()->create(['state' => MatchState::InProgress]);
    $match->update(['state' => MatchState::Complete]);

    Bus::assertDispatched(RunSyncJob::class);
});

it('schedules the completion sync about thirty seconds out so closing the app soon after rarely strands the match', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    Bus::fake([RunSyncJob::class]);

    $match = MtgoMatch::factory()->create(['state' => MatchState::InProgress]);
    $match->update(['state' => MatchState::Complete]);

    Bus::assertDispatched(RunSyncJob::class, fn (RunSyncJob $job) => $job->delay instanceof DateTimeInterface
        && abs(now()->diffInSeconds($job->delay)) <= 30);
});

it('does not schedule a completion sync while unlinked', function () {
    app(SyncTokens::class)->clear();
    Bus::fake([RunSyncJob::class]);

    $match = MtgoMatch::factory()->create(['state' => MatchState::InProgress]);
    $match->update(['state' => MatchState::Complete]);

    Bus::assertNotDispatched(RunSyncJob::class);
});
