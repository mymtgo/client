<?php

use App\Facades\AppSettings;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake();
});

it('defaults autostart to false', function () {
    expect(AppSettings::autostartEnabled())->toBeFalse();
});

it('persists autostart enabled', function () {
    AppSettings::setAutostartEnabled(true);

    expect(AppSettings::autostartEnabled())->toBeTrue();
});

it('persists autostart disabled', function () {
    AppSettings::setAutostartEnabled(true);
    AppSettings::setAutostartEnabled(false);

    expect(AppSettings::autostartEnabled())->toBeFalse();
});
