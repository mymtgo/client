<?php

use App\Data\Casts\LocalTimeCast;
use Carbon\Carbon;
use Native\Desktop\Facades\Settings;
use Spatie\LaravelData\Support\Creation\CreationContext;
use Spatie\LaravelData\Support\DataProperty;

it('converts UTC carbon to local timezone', function () {
    Settings::set('system_tz', 'America/Los_Angeles');

    $cast = new LocalTimeCast;

    $utc = Carbon::parse('2026-04-07 19:19:15', 'UTC');
    $result = $cast->cast(
        Mockery::mock(DataProperty::class),
        $utc,
        [],
        Mockery::mock(CreationContext::class),
    );

    expect($result)->toBeInstanceOf(Carbon::class);
    expect($result->timezone->getName())->toBe('America/Los_Angeles');
    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-07 12:19:15');
});

it('handles string timestamp input', function () {
    Settings::set('system_tz', 'America/New_York');

    $cast = new LocalTimeCast;

    $result = $cast->cast(
        Mockery::mock(DataProperty::class),
        '2026-04-01 16:00:00',
        [],
        Mockery::mock(CreationContext::class),
    );

    expect($result)->toBeInstanceOf(Carbon::class);
    expect($result->timezone->getName())->toBe('America/New_York');
    expect($result->format('Y-m-d H:i:s'))->toBe('2026-04-01 12:00:00');
});

it('returns null for null input', function () {
    $cast = new LocalTimeCast;

    $result = $cast->cast(
        Mockery::mock(DataProperty::class),
        null,
        [],
        Mockery::mock(CreationContext::class),
    );

    expect($result)->toBeNull();
});
