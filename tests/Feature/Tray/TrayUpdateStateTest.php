<?php

use App\Actions\AutoUpdate\ResolveUpdateStatus;
use App\Actions\Tray\SyncTrayUpdateState;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Linux') {
        $this->markTestSkipped('Tray menu bar is not created on Linux.');
    }

    config(['nativephp.version' => '0.44.0']);
    Cache::forget(ResolveUpdateStatus::DOWNLOADED_KEY);
    Cache::forget(ResolveUpdateStatus::LAST_CHECK_KEY);
});

function lastTrayRequest(string $endpoint): ?Request
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->last(fn (Request $request) => str_contains($request->url(), $endpoint));
}

it('pushes the badge icon and tooltip to a running tray', function () {
    ResolveUpdateStatus::recordDownloaded('0.45.0');

    SyncTrayUpdateState::run();

    expect(lastTrayRequest('menu-bar/icon')['icon'])->toContain('update')
        ->and(lastTrayRequest('menu-bar/tooltip')['tooltip'])->toBe('mymtgo: update ready (v0.45.0)');
});

it('restores the normal icon and tooltip when nothing is ready', function () {
    SyncTrayUpdateState::run();

    expect(lastTrayRequest('menu-bar/icon')['icon'])->not->toContain('update')
        ->and(lastTrayRequest('menu-bar/tooltip')['tooltip'])->toBe('mymtgo');
});

it('syncs the tray icon when an update finishes downloading', function () {
    event(new UpdateDownloaded(downloadedFile: '/tmp/x.exe', version: '0.45.0', files: [], releaseDate: '2026-10-01'));

    expect(lastTrayRequest('menu-bar/icon')['icon'])->toContain('update');
});

it('never replaces the context menu of a running tray', function () {
    // The tray is context-menu-only: /menu-bar/context-menu calls
    // tray.setContextMenu(), which on Windows turns left-click into "pop
    // menu" and stops MenuBarClicked from opening the window.
    ResolveUpdateStatus::recordDownloaded('0.45.0');

    SyncTrayUpdateState::run();

    expect(lastTrayRequest('menu-bar/context-menu'))->toBeNull();
});
