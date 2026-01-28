<?php

namespace App\Managers;

use App\Actions\Logs\GetLogFilePaths;
use App\Jobs\DownloadArchetypes;
use App\Jobs\PopulateMissingCardData;
use App\Jobs\ProcessLogEvents;
use App\Jobs\StoreGameLogs;
use App\Jobs\SyncDecks;
use App\Models\Archetype;
use App\Models\Deck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Facades\Settings;

class MtgoManager
{
    protected $logFileMissing = false;

    protected ?string $username = null;

    public function isConfigured(): bool
    {
        return ! $this->logFileMissing() && $this->getUsername();
    }

    public function getLogPath(): ?string
    {
        return Settings::get('log_path', Storage::disk('user_home')->path('\\AppData\\Local\\Apps\\2.0'));
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

    public function getLogDataPath(): ?string
    {
        return Settings::get('log_data_path');
    }

    public function setUsername(string $username): string
    {
        $this->username = $username;

        return $this->getUsername();
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function runInitialSetup()
    {
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

    public function schedule(Schedule $schedule): void
    {
        $pause = false;

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

//        $schedule->call(
//            fn () => $this->processLogEvents()
//        )->everyThirtySeconds()->name('process_log_events')->withoutOverlapping(30);

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
