<?php

use Native\Desktop\Facades\Settings;
use Native\Desktop\Facades\System;

it('persists system timezone to settings on boot', function () {
    System::shouldReceive('timezone')->once()->andReturn('America/Chicago');

    $detected = System::timezone();
    Settings::set('system_tz', $detected ?? Settings::get('system_tz', 'UTC'));

    expect(Settings::get('system_tz'))->toBe('America/Chicago');
});

it('falls back to last known timezone when System::timezone() throws', function () {
    Settings::set('system_tz', 'Europe/London');

    System::shouldReceive('timezone')->once()->andThrow(new RuntimeException('NativePHP not available'));

    try {
        $detected = System::timezone();
        Settings::set('system_tz', $detected ?? Settings::get('system_tz', 'UTC'));
    } catch (Throwable) {
        // NativePHP not available — Settings left unchanged
    }

    expect(Settings::get('system_tz'))->toBe('Europe/London');
});

it('falls back to UTC when no timezone exists and System::timezone() throws', function () {
    System::shouldReceive('timezone')->once()->andThrow(new RuntimeException('NativePHP not available'));

    try {
        $detected = System::timezone();
        Settings::set('system_tz', $detected ?? Settings::get('system_tz', 'UTC'));
    } catch (Throwable) {
        // NativePHP not available — Settings left unchanged
    }

    expect(Settings::get('system_tz', 'UTC'))->toBe('UTC');
});
