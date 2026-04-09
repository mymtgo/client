<?php

use Carbon\Carbon;
use Native\Desktop\Facades\Settings;

it('converts UTC carbon to local timezone via toLocal macro', function () {
    Settings::set('system_tz', 'America/New_York');

    $utc = Carbon::parse('2026-04-01 16:00:00', 'UTC');
    $local = $utc->toLocal();

    expect($local->timezone->getName())->toBe('America/New_York');
    expect($local->format('Y-m-d H:i:s'))->toBe('2026-04-01 12:00:00');
});

it('does not mutate the original carbon instance', function () {
    Settings::set('system_tz', 'America/New_York');

    $utc = Carbon::parse('2026-04-01 16:00:00', 'UTC');
    $utc->toLocal();

    expect($utc->timezone->getName())->toBe('UTC');
    expect($utc->format('Y-m-d H:i:s'))->toBe('2026-04-01 16:00:00');
});

it('falls back to UTC when no timezone is set', function () {
    $utc = Carbon::parse('2026-04-01 16:00:00', 'UTC');
    $local = $utc->toLocal();

    expect($local->timezone->getName())->toBe('UTC');
    expect($local->format('Y-m-d H:i:s'))->toBe('2026-04-01 16:00:00');
});
