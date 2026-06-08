<?php

use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Jobs\RefreshArchetypes;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;

it('registers the hourly refresh_archetypes schedule', function () {
    $schedule = app(Schedule::class);

    Mtgo::schedule($schedule);

    $names = collect($schedule->events())->map(fn ($e) => $e->description);

    expect($names)->toContain('refresh_archetypes');
});

it('dispatches RefreshArchetypes when the refresh_archetypes scheduled task fires', function () {
    // The scheduler always pushes the parent onto the queue when the cron
    // hits; the staleness/in-progress short-circuit lives inside the job's
    // handle() and is covered by RefreshArchetypesTest. This test just
    // proves the schedule entry is wired up to enqueue the right job.
    Bus::fake();
    AppSettings::forget('archetypes_last_refreshed_at');

    $schedule = app(Schedule::class);
    Mtgo::schedule($schedule);

    $event = collect($schedule->events())->firstWhere('description', 'refresh_archetypes');
    expect($event)->not->toBeNull();
    $event->run(app());

    Bus::assertDispatched(RefreshArchetypes::class);
});
