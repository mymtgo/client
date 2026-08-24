<?php

use App\Facades\AppSettings;
use App\Updates\RenameShareStatsToOfflineMode;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake());

it('puts a user who shared stats online', function () {
    Storage::disk()->put('settings.json', '{}');
    AppSettings::set('share_stats', true);

    (new RenameShareStatsToOfflineMode)->run();

    expect(AppSettings::isOffline())->toBeFalse()
        ->and(AppSettings::get('share_stats'))->toBeNull();
});

it('puts a user who opted out into offline mode', function () {
    Storage::disk()->put('settings.json', '{}');
    AppSettings::set('share_stats', false);

    (new RenameShareStatsToOfflineMode)->run();

    expect(AppSettings::isOffline())->toBeTrue()
        ->and(AppSettings::get('share_stats'))->toBeNull();
});

it('defaults to online when share_stats was never written', function () {
    Storage::disk()->put('settings.json', '{}');

    (new RenameShareStatsToOfflineMode)->run();

    expect(AppSettings::isOffline())->toBeFalse();
});

it('does not clobber an existing offline_mode value on a second run', function () {
    Storage::disk()->put('settings.json', '{}');
    AppSettings::set('share_stats', false);

    (new RenameShareStatsToOfflineMode)->run();
    (new RenameShareStatsToOfflineMode)->run();

    expect(AppSettings::isOffline())->toBeTrue();
});

it('defers when settings.json has not been migrated yet', function () {
    AppSettings::set('share_stats', false);

    expect(fn () => (new RenameShareStatsToOfflineMode)->run())
        ->toThrow(RuntimeException::class);

    expect(AppSettings::get('offline_mode'))->toBeNull()
        ->and(AppSettings::get('share_stats'))->toBeFalse();
});
