<?php

namespace App\Managers;

use App\Actions\Logs\GetLogFilePaths;
use App\Actions\RegisterDevice;
use App\Actions\Settings\ValidatePath;
use App\Jobs\DownloadArchetypes;
use App\Jobs\PopulateMissingCardData;
use App\Jobs\ProcessLogEvents;
use App\Jobs\StoreGameLogs;
use App\Jobs\SubmitMatch;
use App\Jobs\SyncDecks;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\LogCursor;
use App\Models\MtgoMatch;
use Illuminate\Console\Scheduling\Schedule;
use Native\Desktop\Facades\Settings;

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
        return Settings::get('log_path') ?: $this->defaultLogPath();
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
        return Settings::get('log_data_path') ?: $this->defaultDataPath();
    }

    public function setUsername(string $username): string
    {
        $this->username = $username;

        return $this->getUsername();
    }

    public function getUsername(): ?string
    {
        return LogCursor::first()->local_username;
    }

    public function retryUnsubmittedMatches(): void
    {
        if (! Settings::get('share_stats')) {
            return;
        }

        MtgoMatch::whereNull('submitted_at')
            ->whereNotNull('deck_version_id')
            ->whereHas('archetypes')
            ->get()
            ->each(fn (MtgoMatch $match) => SubmitMatch::dispatch($match->id));
    }

    public function runInitialSetup()
    {
        // Seed default paths on first launch so most users need no configuration
        if (! Settings::get('log_path')) {
            Settings::set('log_path', $this->defaultLogPath());
        }

        if (! Settings::get('log_data_path')) {
            Settings::set('log_data_path', $this->defaultDataPath());
        }

        if (! Settings::get('api_key')) {
            RegisterDevice::run();
        }

        if (! Archetype::count()) {
            $this->downloadArchetypes(sync: true);
        }

        if (! Deck::count()) {
            $this->syncDecks(sync: true);
        }

        $this->ingestGameLogs(sync: true);
    }

    public function syncDecks(bool $sync = false): void
    {
        $sync ? SyncDecks::dispatchSync() : SyncDecks::dispatch();
    }

    public function downloadArchetypes(bool $sync = false): void
    {
        $sync ? DownloadArchetypes::dispatchSync() : DownloadArchetypes::dispatch();
    }

    public function ingestLogs(): void
    {
        \App\Actions\Logs\IngestLog::run(
            \App\Actions\Logs\FindMtgoLogPath::run()
        );
    }

    public function ingestGameLogs(bool $sync = false): void
    {
        $sync ? StoreGameLogs::dispatchSync() : StoreGameLogs::dispatch();
    }

    public function processLogEvents(bool $force = false, bool $sync = false): void
    {
        if (Deck::count() || $force) {
            $sync ? ProcessLogEvents::dispatchSync() : ProcessLogEvents::dispatch();
        }
    }

    public function populateMissingCardData(bool $sync = false): void
    {
        $sync ? PopulateMissingCardData::dispatchSync() : PopulateMissingCardData::dispatch();
    }

    public function pathsAreValid(): bool
    {
        $logOk = ValidatePath::forLogs($this->getLogPath() ?? '');
        $dataOk = ValidatePath::forData($this->getLogDataPath() ?? '');

        return $logOk['valid'] && $dataOk['valid'];
    }

    public function schedule(Schedule $schedule): void
    {
        // Submit pending matches every minute — not gated by watcher state or path validity.
        $schedule->call(
            fn () => $this->retryUnsubmittedMatches()
        )->everyMinute()->name('submit_matches')->withoutOverlapping(60);

        try {
            $pause = ! Settings::get('watcher_active', true) || ! $this->pathsAreValid();
        } catch (\Throwable) {
            // NativePHP Settings API not yet available — default to paused.
            return;
        }

        if ($pause) {
            return;
        }

        $schedule->call(
            fn () => \App\Jobs\SyncDecks::dispatch()
        )->everyMinute()->name('sync_decks')->withoutOverlapping(60);

        $schedule->call(
            fn () => $this->ingestGameLogs()
        )->everyTenSeconds()->name('store_game_logs')->withoutOverlapping(10);
        //
        $schedule->call(
            fn () => $this->ingestLogs()
        )->everySecond()->name('ingest_logs')->withoutOverlapping(5);

        $schedule->call(
            fn () => $this->processLogEvents()
        )->everyThirtySeconds()->name('process_log_events')->withoutOverlapping(30);

        $schedule->call(
            fn () => $this->downloadArchetypes()
        )->weekly();

        $schedule->call(
            fn () => $this->populateMissingCardData()
        )->hourly();

        // \Illuminate\Support\Facades\Schedule::call(
        //    fn() => \App\Models\LogEvent::whereNotNull('processed_at')->orWhere(function ($query) {
        //        $query->whereNull('match_token')->whereNull('game_id')->whereNull('match_id');
        //    })->delete()
        // )->everyFiveMinutes();

    }
}
