<?php

namespace App\Actions\Pipeline;

use App\Actions\Matches\ParseGameLogBinary;
use App\Models\GameLog;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\Finder;

/**
 * Import-only: scans a directory for binary game log (.dat) files and creates
 * GameLog rows for each. The live pipeline no longer reads .dat files — game
 * data is synthesised from MetaMessage LogEvents by the walker. This class is
 * retained for the import-from-disk workflow only and will move to the Import
 * namespace in a follow-up task.
 */
class DiscoverGameLogs
{
    /**
     * Discover ALL game log files in the directory, regardless of match state.
     * Used by import to ensure historical game logs are in the DB.
     */
    public static function discoverAll(?string $directory = null): int
    {
        $directory = $directory ?? app('mtgo')->getLogDataPath();

        if (! $directory || ! is_dir($directory)) {
            return 0;
        }

        $finder = (new Finder)
            ->files()
            ->in($directory)
            ->name('*Match_GameLog*')
            ->ignoreUnreadableDirs();

        $discovered = 0;

        foreach ($finder as $file) {
            $parts = explode('_', $file->getFilenameWithoutExtension());
            $token = end($parts);

            $gameLog = GameLog::firstOrCreate(
                ['match_token' => $token],
                ['file_path' => $file->getRealPath()],
            );

            if ($gameLog->wasRecentlyCreated) {
                self::decodeGameLog($gameLog);
                $discovered++;
            }
        }

        return $discovered;
    }

    private static function decodeGameLog(GameLog $gameLog): void
    {
        if (! $gameLog->file_path || ! file_exists($gameLog->file_path)) {
            return;
        }

        try {
            $raw = file_get_contents($gameLog->file_path);
            $parsed = ParseGameLogBinary::run($raw);

            if ($parsed && ! empty($parsed['entries'])) {
                $gameLog->update([
                    'decoded_entries' => $parsed['entries'],
                    'decoded_at' => now(),
                    'byte_offset' => $parsed['byte_offset'],
                    'decoded_version' => ParseGameLogBinary::VERSION,
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning("DiscoverGameLogs: failed to decode {$gameLog->file_path}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
