<?php

use App\Actions\Logs\ConvertMtgoTimestamp;
use Carbon\Carbon;
use Native\Desktop\Facades\Settings;

it('converts local MTGO time to UTC', function () {
    Settings::set('system_tz', 'America/New_York');

    $loggedAt = Carbon::parse('2026-04-01 04:00:00', 'UTC');
    $mtgoTime = '00:00:00';

    $result = ConvertMtgoTimestamp::run($loggedAt, $mtgoTime);

    expect($result->timezone->getName())->toBe('UTC');
    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-01 04:00:00');
});

it('handles BST timezone correctly', function () {
    Settings::set('system_tz', 'Europe/London');

    $loggedAt = Carbon::parse('2026-04-06 08:11:37', 'UTC');
    $mtgoTime = '09:11:37';

    $result = ConvertMtgoTimestamp::run($loggedAt, $mtgoTime);

    expect($result->timezone->getName())->toBe('UTC');
    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-06 08:11:37');
});

it('handles date boundary when local time is previous day', function () {
    Settings::set('system_tz', 'America/New_York');

    $loggedAt = Carbon::parse('2026-04-02 00:00:00', 'UTC');
    $mtgoTime = '20:00:00';

    $result = ConvertMtgoTimestamp::run($loggedAt, $mtgoTime);

    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-02 00:00:00');
});

it('falls back to UTC when no timezone is stored', function () {
    $loggedAt = Carbon::parse('2026-04-01 12:00:00', 'UTC');
    $mtgoTime = '12:00:00';

    $result = ConvertMtgoTimestamp::run($loggedAt, $mtgoTime);

    expect($result->timezone->getName())->toBe('UTC');
    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-01 12:00:00');
});
