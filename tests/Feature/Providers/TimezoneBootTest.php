<?php

use App\Facades\AppSettings;
use Native\Desktop\Facades\System;

it('persists system timezone to settings on boot', function () {
    System::shouldReceive('timezone')->once()->andReturn('America/Chicago');

    $detected = System::timezone();
    AppSettings::setSystemTimezone($detected ?? AppSettings::systemTimezone());

    expect(AppSettings::systemTimezone())->toBe('America/Chicago');
});

it('falls back to last known timezone when System::timezone() throws', function () {
    AppSettings::setSystemTimezone('Europe/London');

    System::shouldReceive('timezone')->once()->andThrow(new RuntimeException('NativePHP not available'));

    try {
        $detected = System::timezone();
        AppSettings::setSystemTimezone($detected ?? AppSettings::systemTimezone());
    } catch (Throwable) {
        // NativePHP not available — AppSettings left unchanged
    }

    expect(AppSettings::systemTimezone())->toBe('Europe/London');
});

it('falls back to UTC when no timezone exists and System::timezone() throws', function () {
    System::shouldReceive('timezone')->once()->andThrow(new RuntimeException('NativePHP not available'));

    try {
        $detected = System::timezone();
        AppSettings::setSystemTimezone($detected ?? AppSettings::systemTimezone());
    } catch (Throwable) {
        // NativePHP not available — AppSettings left unchanged
    }

    expect(AppSettings::systemTimezone())->toBe('UTC');
});
