<?php

namespace App\Managers;

use App\Actions\Logs\FindMtgoLogPath;
use App\Actions\Logs\GetLogFilePaths;
use App\Actions\Logs\IngestLogInstance;
use App\Actions\RegisterDevice;
use App\Actions\Settings\ValidatePath;
use App\Facades\AppSettings;
use App\Jobs\ShipTournamentObservations;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

class MtgoManager
{
    protected $logFileMissing = false;

    protected ?string $username = null;

    public function isConfigured(): bool
    {
        return ! $this->logFileMissing() && $this->getUsername();
    }

    public function defaultLogPath(): string
    {
        $home = getenv('USERPROFILE') ?: getenv('HOMEDRIVE').getenv('HOMEPATH');

        return $home.'\\AppData\\Local\\Apps\\2.0';
    }

    public function defaultDataPath(): string
    {
        return $this->defaultLogPath().'\\Data';
    }

    public function getLogPath(): string
    {
        try {
            return AppSettings::logPath() ?: $this->defaultLogPath();
        } catch (\Throwable) {
            return $this->defaultLogPath();
        }
    }

    public function checkLogPath()
    {
        $logPaths = GetLogFilePaths::run(
            $this->getLogPath()
        );

        if ($logPaths->isEmpty()) {
            $this->logFileMissing = true;
        }
    }

    public function logFileMissing(): bool
    {
        $this->checkLogPath();

        return $this->logFileMissing;
    }

    public function getLogDataPath(): string
    {
        try {
            return AppSettings::logDataPath() ?: $this->defaultDataPath();
        } catch (\Throwable) {
            return $this->defaultDataPath();
        }
    }

    public function setUsername(string $username): string
    {
        $this->username = $username;

        return $this->getUsername();
    }

    /**
     * The local player's MTGO username.
     *
     * v1 resolves this from the bound cloud account (client-agent Task 8:
     * AppAccount + ResolveLocalIdentity). Until that lands, only the
     * in-memory value (set during an active session) is available.
     */
    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function runInitialSetup(): void
    {
        if (AppSettings::logPath() === '') {
            AppSettings::setLogPath($this->defaultLogPath());
        }

        if (AppSettings::logDataPath() === '') {
            AppSettings::setLogDataPath($this->defaultDataPath());
        }

        if (AppSettings::deviceId() === null) {
            AppSettings::setDeviceId((string) Str::uuid());
        }

        // Bool settings: seed only when the key has never been written
        // (raw get returns null). Explicit false must be preserved.
        if (AppSettings::get('share_stats') === null) {
            AppSettings::setShouldTransmitMatches(true);
        }
        if (AppSettings::get('watcher_active') === null) {
            AppSettings::setWatcherActive(true);
        }
        if (AppSettings::get('debug_mode') === null) {
            AppSettings::setDebugMode(false);
        }
        if (AppSettings::get('league_window') === null) {
            AppSettings::setShowLeagueWindow(false);
        }
        if (AppSettings::get('opponent_window') === null) {
            AppSettings::setShowOpponentWindow(false);
        }
        if (AppSettings::get('deck_window') === null) {
            AppSettings::setShowDeckWindow(false);
        }
        if (AppSettings::get('local_images') === null) {
            AppSettings::setDownloadImagesLocally(false);
        }
        if (AppSettings::get('system_tz') === null) {
            AppSettings::setSystemTimezone('UTC');
        }

        $expiresAt = AppSettings::apiKeyExpiresAt();
        $expired = $expiresAt && now()->isAfter($expiresAt);

        if (! RegisterDevice::retrieveKey() || $expired) {
            RegisterDevice::run();
        }
    }

    public function canRun(): bool
    {
        try {
            return AppSettings::isWatcherActive() && $this->pathsAreValid();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ingestLogs(): void
    {
        if (! $this->canRun()) {
            return;
        }

        FindMtgoLogPath::all()->each(fn (string $path) => IngestLogInstance::run($path));
    }

    public function pathsAreValid(): bool
    {
        $logOk = ValidatePath::forLogs($this->getLogPath());

        return $logOk['valid'];
    }

    public function schedule(Schedule $schedule): void
    {
        // NOTE: the ingest/compile/push pipeline tick is rebuilt in
        // client-agent Task 2b (RunPipelineTick + Electron fs.watch). It is
        // intentionally absent here until that seam exists.

        $schedule->job(new ShipTournamentObservations)
            ->everyThirtySeconds()
            ->name('ship_tournament_observations')
            ->withoutOverlapping(60);
    }
}
