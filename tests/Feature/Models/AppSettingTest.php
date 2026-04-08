<?php

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the stored timezone as display timezone', function () {
    AppSetting::resolve()->update(['timezone' => 'America/New_York']);

    expect(AppSetting::displayTimezone())->toBe('America/New_York');
});

it('returns UTC when no timezone is set', function () {
    AppSetting::resolve()->update(['timezone' => null]);

    expect(AppSetting::displayTimezone())->toBe('UTC');
});
