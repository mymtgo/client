<?php

use App\Actions\Logs\ConvertMtgoTimestamp;
use App\Facades\AppSettings;
use Carbon\Carbon;

it('converts local MTGO time to UTC', function () {
    AppSettings::setSystemTimezone('America/New_York');

    $loggedAt = Carbon::parse('2026-04-01 04:00:00', 'UTC');
    $mtgoTime = '00:00:00';

    $result = ConvertMtgoTimestamp::run($loggedAt, $mtgoTime);

    expect($result->timezone->getName())->toBe('UTC');
    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-01 04:00:00');
});

it('handles BST timezone correctly', function () {
    AppSettings::setSystemTimezone('Europe/London');

    $loggedAt = Carbon::parse('2026-04-06 08:11:37', 'UTC');
    $mtgoTime = '09:11:37';

    $result = ConvertMtgoTimestamp::run($loggedAt, $mtgoTime);

    expect($result->timezone->getName())->toBe('UTC');
    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-06 08:11:37');
});

it('handles date boundary when local time is previous day', function () {
    AppSettings::setSystemTimezone('America/New_York');

    $loggedAt = Carbon::parse('2026-04-02 00:00:00', 'UTC');
    $mtgoTime = '20:00:00';

    $result = ConvertMtgoTimestamp::run($loggedAt, $mtgoTime);

    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-02 00:00:00');
});

it('keeps the previous day when the file rolls over midnight before ingest', function () {
    AppSettings::setSystemTimezone('UTC');

    // MTGO wrote the line at 23:59:58; we stat'd the file a few seconds later,
    // by which point the mtime had ticked into the next day.
    $loggedAt = Carbon::parse('2026-07-11 00:00:03', 'UTC');

    $result = ConvertMtgoTimestamp::run($loggedAt, '23:59:58');

    expect($result->format('Y-m-d H:i:s'))->toBe('2026-07-10 23:59:58');
});

it('keeps the previous day across midnight in a non-UTC timezone', function () {
    AppSettings::setSystemTimezone('Europe/London');

    // 2026-07-10 23:59:58 BST === 2026-07-10 22:59:58 UTC.
    $loggedAt = Carbon::parse('2026-07-10 23:00:04', 'UTC'); // 00:00:04 BST on the 11th

    $result = ConvertMtgoTimestamp::run($loggedAt, '23:59:58');

    expect($result->format('Y-m-d H:i:s'))->toBe('2026-07-10 22:59:58');
});

it('does not roll back when the line is only slightly ahead of the file mtime', function () {
    AppSettings::setSystemTimezone('UTC');

    // Lines appended between the stat() and the read are legitimately ahead of
    // the recorded mtime. Rolling those back a day would be far worse.
    $loggedAt = Carbon::parse('2026-07-10 12:00:00', 'UTC');

    $result = ConvertMtgoTimestamp::run($loggedAt, '12:00:05');

    expect($result->format('Y-m-d H:i:s'))->toBe('2026-07-10 12:00:05');
});

it('falls back to UTC when no timezone is stored', function () {
    $loggedAt = Carbon::parse('2026-04-01 12:00:00', 'UTC');
    $mtgoTime = '12:00:00';

    $result = ConvertMtgoTimestamp::run($loggedAt, $mtgoTime);

    expect($result->timezone->getName())->toBe('UTC');
    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-01 12:00:00');
});
