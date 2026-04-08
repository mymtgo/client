<?php

use App\Actions\Logs\ConvertMtgoTimestamp;
use App\Models\AppSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => AppSetting::clearCache());

it('converts local MTGO time to UTC', function () {
    AppSetting::firstOrCreate(['id' => 1])->update(['timezone' => 'America/New_York']);
    AppSetting::clearCache();

    // April in New York is EDT (UTC-4)
    $loggedAt = Carbon::parse('2026-04-01 04:00:00', 'UTC'); // file mtime in UTC
    $mtgoTime = '00:00:00'; // midnight local EDT

    $result = ConvertMtgoTimestamp::run($loggedAt, $mtgoTime);

    expect($result->timezone->getName())->toBe('UTC');
    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-01 04:00:00');
});

it('handles BST timezone correctly', function () {
    AppSetting::firstOrCreate(['id' => 1])->update(['timezone' => 'Europe/London']);
    AppSetting::clearCache();

    // April 6 in BST (UTC+1): local 09:11 = UTC 08:11
    $loggedAt = Carbon::parse('2026-04-06 08:11:37', 'UTC');
    $mtgoTime = '09:11:37';

    $result = ConvertMtgoTimestamp::run($loggedAt, $mtgoTime);

    expect($result->timezone->getName())->toBe('UTC');
    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-06 08:11:37');
});

it('handles date boundary when local time is previous day', function () {
    AppSetting::firstOrCreate(['id' => 1])->update(['timezone' => 'America/New_York']);
    AppSetting::clearCache();

    // UTC midnight April 2 = 8pm April 1 in EDT (UTC-4)
    $loggedAt = Carbon::parse('2026-04-02 00:00:00', 'UTC');
    $mtgoTime = '20:00:00'; // 8pm local on April 1

    $result = ConvertMtgoTimestamp::run($loggedAt, $mtgoTime);

    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-02 00:00:00');
});

it('falls back to UTC when no timezone is stored', function () {
    AppSetting::firstOrCreate(['id' => 1])->update(['timezone' => null]);
    AppSetting::clearCache();

    $loggedAt = Carbon::parse('2026-04-01 12:00:00', 'UTC');
    $mtgoTime = '12:00:00';

    $result = ConvertMtgoTimestamp::run($loggedAt, $mtgoTime);

    expect($result->timezone->getName())->toBe('UTC');
    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-01 12:00:00');
});
