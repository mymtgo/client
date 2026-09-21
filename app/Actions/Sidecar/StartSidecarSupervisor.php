<?php

namespace App\Actions\Sidecar;

use App\Facades\AppSettings;
use App\Sidecar\SidecarPaths;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Facades\ChildProcess;

/**
 * Boots the bundled .NET sidecar process on Windows builds that ship one,
 * and counts crashes reported back through ProcessExited so a
 * crash-looping sidecar trips itself off instead of respawning forever.
 *
 * Electron's persistent:true flag handles the actual respawn; this class
 * only decides whether to start at all and when to give up.
 */
class StartSidecarSupervisor
{
    public const ALIAS = 'mtgo-sidecar';

    public const CRASH_LIMIT = 5;

    public const CRASH_WINDOW_SECONDS = 600;

    /**
     * Boot-time entry. $exePath is injectable for tests; production passes
     * nothing and resolves the bundled exe via SidecarPaths::exe().
     */
    public static function run(?string $exePath = null): void
    {
        $exe = $exePath ?? SidecarPaths::exe();

        AppSettings::setSidecarAvailable($exe !== null);
        AppSettings::setSidecarTripped(false);
        AppSettings::clearSidecarCrashes();

        if ($exe === null || ! AppSettings::sidecarEnabled()) {
            return;
        }

        $dir = SidecarPaths::directory();
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $configUrl = AppSettings::isOffline()
            ? ''
            : rtrim((string) AppSettings::appServerUrl(), '/').'/sidecar/config';

        ChildProcess::start(
            cmd: [$exe, '--out', $dir, '--parent-pid', (string) getmypid(), '--config', $configUrl],
            alias: self::ALIAS,
            persistent: true,
        );

        Log::channel('pipeline')->info('Sidecar started', [
            'exe' => $exe,
            'out' => $dir,
            'config' => $configUrl !== '',
        ]);
    }

    /**
     * Called on every ProcessExited for the sidecar alias.
     *
     * @return bool true when the tripwire fired and the process was stopped
     */
    public static function handleExit(): bool
    {
        // A user switching the sidecar off stops the child, and Electron
        // reports that as an ordinary exit. Counting those would let two
        // toggles plus three real crashes trip the wire, which then has to
        // be cleared by a restart. Only exits that happen while the sidecar
        // is meant to be running are crashes.
        if (! AppSettings::sidecarEnabled()) {
            return false;
        }

        $count = AppSettings::recordSidecarCrash(self::CRASH_WINDOW_SECONDS);

        if ($count < self::CRASH_LIMIT) {
            return false;
        }

        ChildProcess::stop(self::ALIAS);
        AppSettings::setSidecarTripped(true);

        Log::channel('pipeline')->error('Sidecar tripped: too many exits', [
            'count' => $count,
            'window_s' => self::CRASH_WINDOW_SECONDS,
        ]);

        return true;
    }
}
