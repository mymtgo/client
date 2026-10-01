<?php

use App\Events\LeagueOverlayChanged;
use App\Facades\AppSettings;
use App\Jobs\PublishOverlayStateJob;
use App\Managers\MtgoManager;
use App\Services\Sync\SyncTokens;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Queue;

function publishOverlayScheduledEvent(): Event
{
    $schedule = app(Schedule::class);

    if (empty($schedule->events())) {
        app(MtgoManager::class)->schedule($schedule);
    }

    return collect($schedule->events())
        ->first(fn ($event) => str_contains((string) $event->description, 'publish_overlay'))
        ?? throw new RuntimeException('No scheduled event matching publish_overlay');
}

it('queues a publish on overlay changes while publishing is on, window or not', function () {
    Queue::fake();
    AppSettings::setOverlayPublish(true);
    AppSettings::setShowLeagueWindow(false);

    LeagueOverlayChanged::dispatch();

    Queue::assertPushedOn('overlay', PublishOverlayStateJob::class);
});

it('queues nothing while publishing is off', function () {
    Queue::fake();

    LeagueOverlayChanged::dispatch();

    Queue::assertNotPushed(PublishOverlayStateJob::class);
});

it('collapses bursts into one pending job', function () {
    expect(new PublishOverlayStateJob)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
});

it('runs a dedicated overlay queue worker', function () {
    expect(config('nativephp.queue_workers.overlay.queues'))->toBe(['overlay']);
});

it('heartbeats every thirty seconds only while publishing and linked', function () {
    $event = publishOverlayScheduledEvent();

    expect($event->expression)->toBe('* * * * *')
        ->and($event->repeatSeconds)->toBe(30)
        ->and($event->filtersPass(app()))->toBeFalse();

    AppSettings::setOverlayPublish(true);
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);

    expect($event->filtersPass(app()))->toBeTrue();
});

it('bounds the unique lock so a lost job cannot block publishing for a day', function () {
    expect((new PublishOverlayStateJob)->uniqueFor)->toBe(60);
});
