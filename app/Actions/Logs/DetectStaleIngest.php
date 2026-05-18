<?php

namespace App\Actions\Logs;

use App\Facades\Mtgo;
use App\Models\LogCursor;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\Finder;

class DetectStaleIngest
{
    /**
     * A cursor that advanced within this window is considered healthy, and a log file
     * with mtime within this window is considered actively-written. Same constant for
     * both sides: if the freshest cursor advance lags the freshest file activity by
     * more than this many seconds, the path cache is stale and gets invalidated.
     */
    public const FRESHNESS_WINDOW_SECONDS = 30;

    /**
     * Returns true if cache was invalidated.
     */
    public static function run(): bool
    {
        $cutoff = now()->subSeconds(self::FRESHNESS_WINDOW_SECONDS);

        // Cheap DB short-circuit: if any cursor advanced inside the freshness window,
        // ingestion is healthy and we skip the filesystem scan entirely.
        $recentAdvanceExists = LogCursor::query()
            ->where('last_advanced_at', '>=', $cutoff)
            ->exists();

        if ($recentAdvanceExists) {
            return false;
        }

        $logPath = Mtgo::getLogPath();

        if (empty($logPath) || ! is_dir($logPath)) {
            return false;
        }

        try {
            $finder = Finder::create()
                ->files()
                ->name('mtgo.log')
                ->in($logPath)
                ->ignoreUnreadableDirs()
                ->depth('< 8')
                ->date('>= '.self::FRESHNESS_WINDOW_SECONDS.' seconds ago');

            $hasRecentFile = $finder->hasResults();
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning("DetectStaleIngest: scan failed: {$e->getMessage()}");

            return false;
        }

        if (! $hasRecentFile) {
            return false;
        }

        Log::channel('pipeline')->info('DetectStaleIngest: stale state detected — forgetting log path cache', [
            'log_path' => $logPath,
        ]);

        FindMtgoLogPath::forgetCache();

        return true;
    }
}
