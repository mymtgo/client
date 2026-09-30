<?php

use App\Actions\AutoUpdate\ResolveUpdateStatus;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Events\AutoUpdater\CheckingForUpdate;
use Native\Desktop\Events\AutoUpdater\Error;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Native\Desktop\Events\AutoUpdater\UpdateNotAvailable;

beforeEach(function () {
    config(['nativephp.version' => '0.44.0']);
    Cache::forget(ResolveUpdateStatus::DOWNLOADED_KEY);
    Cache::forget(ResolveUpdateStatus::LAST_CHECK_KEY);
});

it('records checking', function () {
    event(new CheckingForUpdate);

    expect(ResolveUpdateStatus::run()['status'])->toBe('checking');
});

it('records an available update as downloading', function () {
    event(new UpdateAvailable(version: '0.45.0', files: [], releaseDate: '2026-10-01'));

    expect(ResolveUpdateStatus::run())
        ->status->toBe('downloading')
        ->available->toBe('0.45.0');
});

it('records a downloaded update as ready', function () {
    event(new UpdateDownloaded(downloadedFile: '/tmp/x.exe', version: '0.45.0', files: [], releaseDate: '2026-10-01'));

    expect(ResolveUpdateStatus::run())
        ->status->toBe('ready')
        ->available->toBe('0.45.0');
});

it('records no update as up_to_date with a check time', function () {
    event(new UpdateNotAvailable(version: '0.44.0', files: [], releaseDate: '2026-09-29'));

    expect(ResolveUpdateStatus::run())
        ->status->toBe('up_to_date')
        ->checkedAt->not->toBeNull();
});

it('records errors', function () {
    event(new Error(name: 'Error', message: 'net::ERR_INTERNET_DISCONNECTED'));

    expect(ResolveUpdateStatus::run())
        ->status->toBe('error')
        ->error->toBe('net::ERR_INTERNET_DISCONNECTED');
});

it('registers exactly one listener per updater event', function () {
    expect(app('events')->getListeners(UpdateDownloaded::class))->toHaveCount(1);
});
