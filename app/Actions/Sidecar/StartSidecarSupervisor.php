<?php

namespace App\Actions\Sidecar;

use App\Facades\AppSettings;
use App\Jobs\DownloadSidecarJob;
use App\Sidecar\SidecarDownloadState;
use App\Sidecar\SidecarDownloadStore;
use App\Sidecar\SidecarPaths;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Native\Desktop\Facades\ChildProcess;

/**
 * Boots the helper process on Windows, downloading the pinned release first
 * when it is missing, and counts crashes reported back through ProcessExited so a
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
     * Boot-time entry, also called by the enable toggle and by
     * DownloadSidecarJob once a download verifies. $exePath is injectable for
     * tests. $parentPid is the process the helper watches and exits with;
     * the job passes the pid recorded at boot, because its own worker
     * process is recycled and would take the helper down with it.
     *
     * The exe is published self-contained, carrying its own .NET runtime,
     * so a present exe is all "available" needs.
     */
    public static function run(?string $exePath = null, ?int $parentPid = null): void
    {
        $exe = $exePath ?? SidecarPaths::exe();

        AppSettings::setSidecarAvailable($exe !== null);
        AppSettings::setSidecarTripped(false);
        AppSettings::clearSidecarCrashes();

        if (! AppSettings::sidecarEnabled()) {
            return;
        }

        if (SidecarPaths::supported()) {
            self::sweepBinDirectory();
        }

        if ($exe === null) {
            if (SidecarPaths::supported()) {
                self::ensureDownloaded();
            }

            return;
        }

        $dir = SidecarPaths::directory();
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $configUrl = AppSettings::isOffline() ? '' : (string) self::CONFIG_URL;

        ChildProcess::start(
            cmd: [$exe, '--out', $dir, '--parent-pid', (string) ($parentPid ?? getmypid()), '--config', $configUrl],
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
     * Queues a fresh download under a new run id. Any unique lock left by a
     * killed app is released first; the new run id makes the old job, if it
     * ever runs, drop out on its first state write.
     */
    public static function startDownload(): void
    {
        $version = (string) config('sidecar.version');
        $runId = (string) Str::uuid();

        SidecarDownloadStore::write(SidecarDownloadState::downloading($version, $runId));

        $job = new DownloadSidecarJob($runId);
        (new UniqueLock(Cache::driver()))->release($job);

        dispatch($job);
    }

    private static function ensureDownloaded(): void
    {
        $version = (string) config('sidecar.version');
        $state = SidecarDownloadStore::read();

        if ($state->isFor($version)) {
            if ($state->status === SidecarDownloadState::READY) {
                // Verified, then gone: almost always antivirus quarantine.
                // Re-downloading every boot would just feed it the same file.
                SidecarDownloadStore::write(SidecarDownloadState::failed($version, $state->runId, SidecarDownloadState::ERROR_QUARANTINED));
                Log::channel('pipeline')->warning('Sidecar exe missing after verification', ['version' => $version]);

                return;
            }

            if ($state->status === SidecarDownloadState::FAILED && $state->error === SidecarDownloadState::ERROR_QUARANTINED) {
                return;
            }

            if ($state->status === SidecarDownloadState::DOWNLOADING && ! $state->isStale()) {
                return;
            }
        }

        if (AppSettings::isOffline()) {
            return;
        }

        self::startDownload();
    }

    /**
     * Removes what a killed app or a finished upgrade leaves in the bin dir:
     * exes for other versions (not running at boot), partial downloads from
     * runs that no longer own the state, and torn state temp files. Locked
     * files are left for next time.
     */
    private static function sweepBinDirectory(): void
    {
        foreach (SidecarPaths::staleExes() as $file) {
            @unlink($file);
        }

        $state = SidecarDownloadStore::read();
        $liveRun = $state->status === SidecarDownloadState::DOWNLOADING && ! $state->isStale() ? $state->runId : null;
        $dir = SidecarPaths::binDirectory();

        foreach (glob($dir.DIRECTORY_SEPARATOR.SidecarPaths::EXE_PREFIX.'*.partial-*') ?: [] as $file) {
            if ($liveRun === null || ! str_ends_with($file, '.partial-'.$liveRun)) {
                @unlink($file);
            }
        }

        // A temp file only lives for the instant between write and rename;
        // anything older is left over from a process that died in between.
        foreach (glob($dir.DIRECTORY_SEPARATOR.'download.json.*.tmp') ?: [] as $file) {
            if (@filemtime($file) < time() - 60) {
                @unlink($file);
            }
        }
    }

    /**
     * Called on every ProcessExited for the sidecar alias.
     *
     * @param  int  $code  the child's exit code as Electron reported it
     * @return bool true when the tripwire fired and the process was stopped
     */
    public static function handleExit(int $code = 0): bool
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
