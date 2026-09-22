<?php

namespace App\Actions\Sidecar;

use App\Facades\AppSettings;
use App\Sidecar\DotnetRuntime;
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

    /** The exe's exit code for bad arguments. Respawning with the same arguments cannot help. */
    public const USAGE_EXIT_CODE = 2;

    /**
     * Remote config source for the exe's version gate and authority flags.
     * Null until the API side (Plan 3) exists. Plan 1 pointed this at the
     * app's own local server, which serves no such route and is http, so the
     * exe rejected it with exit code 2 on every spawn. Empty config means
     * "no remote gate, allow", which is the documented offline behaviour.
     */
    public const CONFIG_URL = null;

    /**
     * Boot-time entry. $exePath and $runtimeInstalled are injectable for
     * tests; production passes nothing and resolves the bundled exe via
     * SidecarPaths::exe() and the runtime via DotnetRuntime.
     *
     * The exe is framework-dependent, so "available" means both the exe is
     * bundled and the .NET Desktop Runtime is installed. A bundled exe with
     * no runtime is recorded as runtime-missing and never spawned: the
     * apphost would exit instantly and feed the crash tripwire.
     */
    public static function run(?string $exePath = null, ?bool $runtimeInstalled = null): void
    {
        $exe = $exePath ?? SidecarPaths::exe();
        $runtimeMissing = $exe !== null && ! ($runtimeInstalled ?? DotnetRuntime::desktopInstalled());

        AppSettings::setSidecarAvailable($exe !== null && ! $runtimeMissing);
        AppSettings::setSidecarRuntimeMissing($runtimeMissing);
        AppSettings::setSidecarTripped(false);
        AppSettings::clearSidecarCrashes();

        if ($runtimeMissing) {
            Log::channel('pipeline')->info('Sidecar not started: .NET Desktop Runtime missing', [
                'required_major' => DotnetRuntime::REQUIRED_MAJOR,
            ]);

            return;
        }

        if ($exe === null || ! AppSettings::sidecarEnabled()) {
            return;
        }

        $dir = SidecarPaths::directory();
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $configUrl = AppSettings::isOffline() ? '' : (string) self::CONFIG_URL;

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
     * @param  int  $code  the child's exit code as Electron reported it
     * @return bool true when the tripwire fired and the process was stopped
     */
    public static function handleExit(int $code = 0): bool
    {
        // The runtime was uninstalled between the boot-time check and now,
        // or the check missed a non-standard install root. Either way this
        // is not a crash and must not respawn: the apphost fails instantly
        // every time and would trip the wire within seconds.
        if (DotnetRuntime::isMissingRuntimeExitCode($code)) {
            ChildProcess::stop(self::ALIAS);
            AppSettings::setSidecarAvailable(false);
            AppSettings::setSidecarRuntimeMissing(true);

            Log::channel('pipeline')->warning('Sidecar exited: .NET Desktop Runtime missing', ['code' => $code]);

            return false;
        }

        // A user switching the sidecar off stops the child, and Electron
        // reports that as an ordinary exit. Counting those would let two
        // toggles plus three real crashes trip the wire, which then has to
        // be cleared by a restart. Only exits that happen while the sidecar
        // is meant to be running are crashes.
        if (! AppSettings::sidecarEnabled()) {
            return false;
        }

        $count = AppSettings::recordSidecarCrash(self::CRASH_WINDOW_SECONDS);

        if ($code !== self::USAGE_EXIT_CODE && $count < self::CRASH_LIMIT) {
            return false;
        }

        // Electron has already dropped its record of the exited process by
        // the time this runs, so this stop is a no-op against the respawn
        // the watchdog has scheduled. The trip flag is what actually ends
        // the loop: handleSpawn stops the respawned process on arrival.
        ChildProcess::stop(self::ALIAS);
        AppSettings::setSidecarTripped(true);

        Log::channel('pipeline')->error('Sidecar tripped', [
            'code' => $code,
            'count' => $count,
            'window_s' => self::CRASH_WINDOW_SECONDS,
            'reason' => $code === self::USAGE_EXIT_CODE ? 'usage error' : 'too many exits',
        ]);

        return true;
    }

    /**
     * Called on every ProcessSpawned for the sidecar alias. A spawn that
     * arrives after the tripwire fired is the watchdog respawn racing the
     * trip; stop it now that Electron has a record to stop.
     *
     * @return bool true when the spawn was stopped
     */
    public static function handleSpawn(): bool
    {
        if (! AppSettings::sidecarTripped()) {
            return false;
        }

        ChildProcess::stop(self::ALIAS);

        Log::channel('pipeline')->info('Sidecar respawn stopped: tripwire is set');

        return true;
    }
}
