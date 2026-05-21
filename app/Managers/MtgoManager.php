<?php

namespace App\Managers;

use App\Actions\Cards\EnqueueCardStats;
use App\Actions\Logs\FindMtgoLogPath;
use App\Actions\Logs\GetLogFilePaths;
use App\Actions\Logs\IngestLogInstance;
use App\Actions\Logs\PruneProcessedLogEvents;
use App\Actions\Pipeline\RunPipeline;
use App\Actions\RegisterDevice;
use App\Actions\Settings\ValidatePath;
use App\Facades\AppSettings;
use App\Jobs\DownloadArchetypes;
use App\Jobs\PopulateMissingCardData;
use App\Jobs\RefreshArchetypes;
use App\Jobs\ShipCardStats;
use App\Jobs\ShipTournamentObservations;
use App\Jobs\SubmitMatch;
use App\Jobs\SyncDecks;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\MtgoMatch;
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

    public function getUsername(): ?string
    {
        return $this->username ?? Account::active()->value('username');
    }

    /**
     * Resolve the local player's username with fallback chain.
     *
     * 1. In-memory username (set during active session)
     * 2. Active account from database
     * 3. Match candidate names against any known account (active or not)
     *
     * @param  array<int, string>  $candidates  Player names to match against known accounts
     */
    public function resolveUsername(array $candidates = []): ?string
    {
        // Fast path: in-memory or active account
        $username = $this->getUsername();
        if ($username) {
            return $username;
        }

        // Fallback: match candidates against any known account
        if (! empty($candidates)) {
            return Account::whereIn('username', $candidates)->value('username');
        }

        return null;
    }

    public function retryUnsubmittedMatches(): void
    {
        if (! AppSettings::shouldTransmitMatches()) {
            return;
        }

        MtgoMatch::submittable()
            ->get()
            ->each(fn (MtgoMatch $match) => SubmitMatch::dispatch($match->id));
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

        if (! Archetype::query()->where('is_fallback', false)->exists()) {
            $this->downloadArchetypes(sync: false);
        }

        if (! Deck::count()) {
            $this->syncDecks(sync: false);
        }

        if ($this->getUsername() && ! Account::exists()) {
            $account = Account::registerAndActivate($this->getUsername());
            Deck::whereNull('account_id')->update(['account_id' => $account->id]);
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

    public function syncDecks(bool $sync = false): void
    {
        if (! $this->canRun()) {
            return;
        }

        $sync ? SyncDecks::dispatchSync() : SyncDecks::dispatch();
    }

    public function downloadArchetypes(bool $sync = false): void
    {
        $sync ? DownloadArchetypes::dispatchSync() : DownloadArchetypes::dispatch();
    }

    public function ingestLogs(): void
    {
        if (! $this->canRun()) {
            return;
        }

        FindMtgoLogPath::all()->each(fn (string $path) => IngestLogInstance::run($path));
    }

    public function populateMissingCardData(bool $sync = false): void
    {
        $sync ? PopulateMissingCardData::dispatchSync() : PopulateMissingCardData::dispatch();
    }

    public function pathsAreValid(): bool
    {
        $logOk = ValidatePath::forLogs($this->getLogPath());
        $dataOk = ValidatePath::forData($this->getLogDataPath());

        return $logOk['valid'] && $dataOk['valid'];
    }

    public function schedule(Schedule $schedule): void
    {
        // ── Unified pipeline (every 2s) ──────────────────────────────
        // Single command owns the entire lifecycle: log ingest →
        // match creation → game log parsing → result resolution.
        $schedule->call(fn () => RunPipeline::run())
            ->everyTwoSeconds()
            ->name('process_matches');

        // Periodic maintenance (unchanged)
        $schedule->call(fn () => $this->retryUnsubmittedMatches())
            ->everyMinute()
            ->name('submit_matches')
            ->withoutOverlapping(60);

        // Pick up new/updated deck XML files so RunPipeline's orphan relinker
        // has fresh DeckVersions to match against.
        $schedule->call(fn () => $this->syncDecks())
            ->everyFiveMinutes()
            ->name('sync_decks')
            ->withoutOverlapping(60);

        $schedule->job(new ShipTournamentObservations)
            ->everyThirtySeconds()
            ->name('ship_tournament_observations')
            ->withoutOverlapping(60);

        $schedule->job(new ShipCardStats)
            ->everyThirtySeconds()
            ->name('ship_card_stats')
            ->withoutOverlapping(60);

        $schedule->call(fn () => EnqueueCardStats::run())
            ->everyMinute()
            ->name('enqueue_card_stats')
            ->withoutOverlapping(60);

        $schedule->call(fn () => $this->downloadArchetypes())
            ->weekly();

        $schedule->call(fn () => $this->populateMissingCardData())
            ->hourly();

        $schedule->call(fn () => PruneProcessedLogEvents::run())
            ->daily()
            ->name('prune_log_events');

        $schedule->job(new RefreshArchetypes)
            ->daily()
            ->name('refresh_archetypes')
            ->withoutOverlapping(120);
    }
}
