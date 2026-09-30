<?php

use App\Actions\AutoUpdate\ResolveUpdateStatus;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config(['nativephp.version' => '0.44.0']);
    Cache::forget(ResolveUpdateStatus::DOWNLOADED_KEY);
    Cache::forget(ResolveUpdateStatus::LAST_CHECK_KEY);
});

it('reports up_to_date with nothing cached', function () {
    expect(ResolveUpdateStatus::run())->toBe([
        'current' => '0.44.0',
        'available' => null,
        'status' => 'up_to_date',
        'checkedAt' => null,
        'error' => null,
        'active' => false,
    ]);
});

it('reports ready when a newer version is downloaded', function () {
    ResolveUpdateStatus::recordDownloaded('0.45.0');

    expect(ResolveUpdateStatus::run())
        ->status->toBe('ready')
        ->available->toBe('0.45.0');
});

it('reports up_to_date when downloaded version is not newer', function (string $downloaded) {
    ResolveUpdateStatus::recordDownloaded($downloaded);

    expect(ResolveUpdateStatus::run())
        ->status->toBe('up_to_date')
        ->available->toBeNull();
})->with(['same' => '0.44.0', 'older' => '0.43.1']);

it('keeps ready after a later error', function () {
    ResolveUpdateStatus::recordDownloaded('0.45.0');
    ResolveUpdateStatus::recordCheck('error', error: 'net::ERR_INTERNET_DISCONNECTED');

    expect(ResolveUpdateStatus::run())
        ->status->toBe('ready')
        ->available->toBe('0.45.0');
});

it('keeps ready after a later check starts', function () {
    ResolveUpdateStatus::recordDownloaded('0.45.0');
    ResolveUpdateStatus::recordCheck('checking');

    expect(ResolveUpdateStatus::run()['status'])->toBe('ready');
});

it('reports checking while a check is fresh', function () {
    ResolveUpdateStatus::recordCheck('checking');

    expect(ResolveUpdateStatus::run()['status'])->toBe('checking');
});

it('reports a check that never finished as an error, never as up to date', function () {
    $this->travelTo(now()->subMinutes(6));
    ResolveUpdateStatus::recordCheck('checking');
    $this->travelBack();

    expect(ResolveUpdateStatus::run())
        ->status->toBe('error')
        ->error->toBe('The update check did not finish. Try again.');
});

it('is inactive outside a packaged production app', function () {
    expect(ResolveUpdateStatus::run()['active'])->toBeFalse();
});

it('is active in a packaged production app', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(ResolveUpdateStatus::run()['active'])->toBeTrue();
});

it('reports downloading with the incoming version', function () {
    ResolveUpdateStatus::recordCheck('downloading', '0.45.0');

    expect(ResolveUpdateStatus::run())
        ->status->toBe('downloading')
        ->available->toBe('0.45.0');
});

it('reports up_to_date when downloading a version that is not newer', function () {
    ResolveUpdateStatus::recordCheck('downloading', '0.44.0');

    expect(ResolveUpdateStatus::run()['status'])->toBe('up_to_date');
});

it('reports errors with their message and time', function () {
    ResolveUpdateStatus::recordCheck('error', error: 'Cannot find latest.yml');

    expect(ResolveUpdateStatus::run())
        ->status->toBe('error')
        ->error->toBe('Cannot find latest.yml')
        ->checkedAt->not->toBeNull();
});

it('forgets a download from a previous session on boot', function () {
    // electron-updater only knows its installer path within one session; a
    // leftover "ready" would make Install do nothing. The boot check
    // re-sends UpdateDownloaded from electron-updater's own cache.
    ResolveUpdateStatus::recordDownloaded('0.45.0');

    ResolveUpdateStatus::startSession();

    expect(ResolveUpdateStatus::run()['status'])->toBe('up_to_date');
});

it('forgets an in-flight check from a previous session on boot', function (string $status) {
    ResolveUpdateStatus::recordCheck($status, '0.45.0');

    ResolveUpdateStatus::startSession();

    expect(ResolveUpdateStatus::run()['status'])->toBe('up_to_date');
})->with(['checking', 'downloading']);

it('keeps the last finished check across boots', function () {
    ResolveUpdateStatus::recordCheck('error', error: 'offline');

    ResolveUpdateStatus::startSession();

    expect(ResolveUpdateStatus::run())
        ->status->toBe('error')
        ->error->toBe('offline');
});
