<?php

namespace App\Actions\AutoUpdate;

use Illuminate\Support\Facades\Cache;

/**
 * Turns the recorded updater events into one status for the UI.
 *
 * Two cache entries are kept apart on purpose: a downloaded update stays
 * installable whatever later checks report, so an offline periodic check
 * (or the next cycle's "checking") never hides the banner.
 */
class ResolveUpdateStatus
{
    public const DOWNLOADED_KEY = 'updater.downloaded';

    public const LAST_CHECK_KEY = 'updater.last_check';

    /**
     * A check that never reported back stops counting as in progress after this.
     */
    private const CHECK_TIMEOUT_MINUTES = 5;

    /**
     * electron-updater skips every check in an unpackaged app and emits no
     * event at all, so a dev build would sit on "checking" forever. Electron
     * launches PHP with APP_ENV=production unless NODE_ENV is development.
     */
    public static function active(): bool
    {
        return app()->isProduction();
    }

    /**
     * @return array{current: string, available: ?string, status: string, checkedAt: ?string, error: ?string, active: bool}
     */
    public static function run(): array
    {
        $current = (string) config('nativephp.version');
        $downloaded = Cache::get(self::DOWNLOADED_KEY);
        $check = Cache::get(self::LAST_CHECK_KEY);

        $checkedAt = $check['at'] ?? null;

        if (isset($downloaded['version']) && self::isNewer($downloaded['version'], $current)) {
            return self::status($current, $downloaded['version'], 'ready', $checkedAt);
        }

        $status = $check['status'] ?? 'up_to_date';

        if ($status === 'checking' && $checkedAt !== null
            && now()->diffInMinutes($checkedAt, absolute: true) > self::CHECK_TIMEOUT_MINUTES) {
            // Unknown is not the same as up to date: say so and let them retry.
            return self::status($current, null, 'error', $checkedAt, 'The update check did not finish. Try again.');
        }

        if ($status === 'downloading') {
            $version = $check['version'] ?? null;

            return $version !== null && self::isNewer($version, $current)
                ? self::status($current, $version, 'downloading', $checkedAt)
                : self::status($current, null, 'up_to_date', $checkedAt);
        }

        return self::status($current, null, $status, $checkedAt, $check['error'] ?? null);
    }

    /**
     * Called once per app launch. electron-updater only knows the installer
     * path within the session that downloaded it, so a "ready" left by a
     * crashed or killed session would make Install do nothing. The boot
     * check re-sends UpdateDownloaded from electron-updater's own cache.
     * An in-flight check from the last session will never report back.
     */
    public static function startSession(): void
    {
        Cache::forget(self::DOWNLOADED_KEY);

        $status = Cache::get(self::LAST_CHECK_KEY)['status'] ?? null;

        if (in_array($status, ['checking', 'downloading'], true)) {
            Cache::forget(self::LAST_CHECK_KEY);
        }
    }

    public static function recordCheck(string $status, ?string $version = null, ?string $error = null): void
    {
        Cache::forever(self::LAST_CHECK_KEY, [
            'status' => $status,
            'version' => $version,
            'error' => $error,
            'at' => now()->toIso8601String(),
        ]);
    }

    public static function recordDownloaded(string $version): void
    {
        Cache::forever(self::DOWNLOADED_KEY, ['version' => $version]);
    }

    private static function isNewer(string $candidate, string $current): bool
    {
        return version_compare(ltrim($candidate, 'v'), ltrim($current, 'v'), '>');
    }

    /**
     * @return array{current: string, available: ?string, status: string, checkedAt: ?string, error: ?string, active: bool}
     */
    private static function status(string $current, ?string $available, string $status, ?string $checkedAt, ?string $error = null): array
    {
        return [
            'current' => $current,
            'available' => $available,
            'status' => $status,
            'checkedAt' => $checkedAt,
            'error' => $status === 'error' ? $error : null,
            'active' => self::active(),
        ];
    }
}
