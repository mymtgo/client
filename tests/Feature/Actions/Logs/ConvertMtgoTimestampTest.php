<?php

use App\Actions\Logs\ConvertMtgoTimestamp;
use Carbon\Carbon;
use Native\Desktop\Facades\System;

it('converts local MTGO time to UTC', function () {
    System::shouldReceive('timezone')->andReturn('America/New_York');

    // April in New York is EDT (UTC-4)
    $loggedAt = Carbon::parse('2026-04-01 04:00:00', 'UTC'); // file mtime in UTC
    $mtgoTime = '00:00:00'; // midnight local EDT

    $result = ConvertMtgoTimestamp::run($loggedAt, $mtgoTime);

    expect($result->timezone->getName())->toBe('UTC');
    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-01 04:00:00');
});

it('handles BST timezone correctly', function () {
    System::shouldReceive('timezone')->andReturn('Europe/London');

    // April 6 in BST (UTC+1): local 09:11 = UTC 08:11
    $loggedAt = Carbon::parse('2026-04-06 08:11:37', 'UTC');
    $mtgoTime = '09:11:37';

    $result = ConvertMtgoTimestamp::run($loggedAt, $mtgoTime);

    expect($result->timezone->getName())->toBe('UTC');
    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-06 08:11:37');
});

it('handles date boundary when local time is previous day', function () {
    System::shouldReceive('timezone')->andReturn('America/New_York');

    // UTC midnight April 2 = 8pm April 1 in EDT (UTC-4)
    $loggedAt = Carbon::parse('2026-04-02 00:00:00', 'UTC');
    $mtgoTime = '20:00:00'; // 8pm local on April 1

    $result = ConvertMtgoTimestamp::run($loggedAt, $mtgoTime);

    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-02 00:00:00');
});
