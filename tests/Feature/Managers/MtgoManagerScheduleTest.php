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

it('dispatches RefreshArchetypes from schedule registration when stale', function () {
    Bus::fake();
    AppSettings::forget('archetypes_last_refreshed_at');

    Mtgo::schedule(app(Schedule::class));

    Bus::assertDispatched(RefreshArchetypes::class);
});

it('does not dispatch RefreshArchetypes when fresh', function () {
    Bus::fake();
    AppSettings::setArchetypesLastRefreshedAt(now()->subHours(2)->toIso8601String());

    Mtgo::schedule(app(Schedule::class));

    Bus::assertNotDispatched(RefreshArchetypes::class);
});
