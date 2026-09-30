<?php

namespace App\Actions\AutoUpdate;

use Illuminate\Support\Facades\Cache;
use Native\Desktop\Facades\AutoUpdater;

/**
 * The single install path for the banner, status bar, settings and tray.
 * quitAndInstall() with no arguments runs the installer non-silently and
 * relaunches, unlike electron's silent install-on-quit.
 */
class InstallDownloadedUpdate
{
    public static function run(): void
    {
        Cache::forget(ResolveUpdateStatus::DOWNLOADED_KEY);

        try {
            AutoUpdater::quitAndInstall();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
