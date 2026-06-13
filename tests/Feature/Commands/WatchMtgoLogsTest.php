<?php

use App\Actions\Pipeline\RunWatchTick;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(LazilyRefreshDatabase::class);

it('runs a single tick and exits with --once', function () {
    Cache::flush();
    RunWatchTick::resetHeartbeatThrottle();

    $this->artisan('mtgo:watch --once')->assertSuccessful();

    expect(Cache::get(RunWatchTick::HEARTBEAT_KEY))->not->toBeNull();
});
