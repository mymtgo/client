<?php

declare(strict_types=1);

namespace App\Services\Sync;

use Illuminate\Support\Facades\Log;

/**
 * The sync run's console feed: one small dedicated log file, reset at the
 * start of every run so the settings card always shows the latest run
 * only. The worker writes it, the web process tails it into the status
 * payload the card polls.
 */
class SyncActivity
{
    public static function reset(): void
    {
        @file_put_contents(self::path(), '');
    }

    public static function log(string $message): void
    {
        Log::channel('sync')->info($message);
    }

    /**
     * Stamped by the settings card's Sync now before the job is dispatched,
     * so the feed reads as active from the click even while the run waits
     * for a worker. The runner's own reset() replaces it the moment the
     * run starts.
     */
    public static function markQueued(): void
    {
        self::reset();
        self::log('Sync queued.');
    }

    /**
     * A run is considered active while the feed's last line is not a
     * terminal one. The mtime guard keeps a crashed worker (which never
     * writes its terminal line) from reading as syncing forever.
     */
    public static function isRunning(): bool
    {
        $tail = self::tail(1);

        if ($tail === []) {
            return false;
        }

        $last = $tail[0];

        if (str_contains($last, 'Sync complete.') || str_contains($last, 'Aborted: ')) {
            return false;
        }

        $mtime = @filemtime(self::path());

        return $mtime !== false && $mtime > time() - 15 * 60;
    }

    /**
     * The last lines of the current run, newest last, with the log
     * boilerplate reduced to a clock time: "[2026-09-03 08:00:00]
     * local.INFO: Sync started" becomes "08:00:00 Sync started".
     *
     * @return list<string>
     */
    public static function tail(int $lines = 40): array
    {
        $raw = @file_get_contents(self::path());

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $tail = array_slice(preg_split('/\r?\n/', trim($raw)), -$lines);

        return array_values(array_map(
            fn (string $line): string => preg_replace('/^\[\d{4}-\d{2}-\d{2} (\d{2}:\d{2}:\d{2})\] \S+: /', '$1 ', $line),
            $tail,
        ));
    }

    private static function path(): string
    {
        return (string) config('logging.channels.sync.path');
    }
}
