<?php

use App\Facades\Mtgo;
use App\Jobs\DownloadArchetypes;
use App\Jobs\RefreshArchetypes;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

it('does not register an automatic archetype refresh', function () {
    $schedule = app(Schedule::class);

    Mtgo::schedule($schedule);

    $names = collect($schedule->events())->map(fn ($e) => $e->description);

    expect($names)->not->toContain('refresh_archetypes');
});

it('never dispatches an archetype download or refresh from any scheduled event', function () {
    // Archetype refresh is a manual, user-triggered process. No scheduled
    // event may dispatch it — running every event proves that, including
    // unnamed closures a description scan would miss.
    Queue::fake();
    Bus::fake();

    $schedule = app(Schedule::class);
    Mtgo::schedule($schedule);

    foreach ($schedule->events() as $event) {
        $event->run(app());
    }

    Queue::assertNotPushed(DownloadArchetypes::class);
    Queue::assertNotPushed(RefreshArchetypes::class);
    Bus::assertNotDispatched(DownloadArchetypes::class);
    Bus::assertNotDispatched(RefreshArchetypes::class);
});
