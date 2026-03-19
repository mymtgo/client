<?php

namespace App\Actions\Matches;

use App\Facades\Mtgo;
use App\Models\GameLog;
use Illuminate\Support\Facades\Log;

class GetGameLog
{
    /**
     * Get structured game results for a match.
     *
     * Uses stored decoded entries if available, otherwise parses the binary
     * .dat file incrementally and stores the result for future access.
     *
     * @return array{results: array<int, bool>, on_play: array<int, bool>, starting_hands: array}|null
     */
    public static function run(string $token): ?array
    {
        $log = GameLog::where('match_token', $token)->first();

        if (! $log) {
            return null;
        }

        $you = Mtgo::getUsername();

        if (! $you) {
            throw new \RuntimeException('MTGO username not set');
        }

        // Sync entries from the .dat file (incremental if partially parsed)
        $entries = self::syncEntries($log);

        if (empty($entries)) {
            Log::channel('pipeline')->warning("GetGameLog: no entries decoded for match token {$token}");

            return null;
        }

        // Extract structured results
        $extracted = ExtractGameResults::run($entries, $you);

        return [
            'results' => $extracted['results'],
            'on_play' => $extracted['on_play'],
            'starting_hands' => $extracted['starting_hands'],
        ];
    }

    /**
     * Ensure decoded_entries is populated and up-to-date.
     *
     * If the .dat file has grown since last parse (byte_offset < file size),
     * incrementally parse new entries and append.
     *
     * @return array<int, array{timestamp: string, message: string}>
     */
    private static function syncEntries(GameLog $log): array
    {
        $entries = $log->decoded_entries ?? [];

        // If already decoded and parser version is current, check if file has grown
        if (! empty($entries) && $log->decoded_version >= ParseGameLogBinary::VERSION) {
            $fileSize = self::getFileSize($log);

            // No new data to parse (file same size or missing)
            if ($fileSize === null || $log->byte_offset >= $fileSize) {
                return $entries;
            }
        }

        // Need to parse (full or incremental)
        $raw = self::readFile($log);

        if ($raw === null) {
            return $entries; // Return whatever we have stored
        }

        $offset = ! empty($entries) ? $log->byte_offset : null;
        $parsed = ParseGameLogBinary::run($raw, $offset);

        if ($parsed === null) {
            return $entries;
        }

        if (! empty($parsed['entries'])) {
            // Deduplication: guard against overlapping entries on app restart
            if (! empty($entries) && ! empty($parsed['entries'])) {
                $lastStored = end($entries);
                $firstNew = $parsed['entries'][0];
                if ($lastStored['timestamp'] === $firstNew['timestamp']
                    && $lastStored['message'] === $firstNew['message']) {
                    array_shift($parsed['entries']);
                }
            }

            $entries = array_merge($entries, $parsed['entries']);

            $log->update([
                'decoded_entries' => $entries,
                'decoded_at' => now(),
                'byte_offset' => $parsed['byte_offset'],
                'decoded_version' => ParseGameLogBinary::VERSION,
            ]);
        }

        return $entries;
    }

    /**
     * Read the raw .dat file contents with Windows path fallback.
     */
    private static function readFile(GameLog $log): ?string
    {
        $raw = @file_get_contents($log->file_path);

        if ($raw === false) {
            $hashDir = basename(dirname(str_replace('\\', '/', $log->file_path)));
            $filename = basename(str_replace('\\', '/', $log->file_path));
            $fallback = storage_path("app/{$hashDir}/{$filename}");
            $raw = @file_get_contents($fallback);
        }

        if ($raw === false) {
            Log::channel('pipeline')->warning('GetGameLog: file not found', [
                'stored_path' => $log->file_path,
            ]);

            return null;
        }

        return $raw;
    }

    /**
     * Get the file size, returning null if file doesn't exist.
     */
    private static function getFileSize(GameLog $log): ?int
    {
        $size = @filesize($log->file_path);

        if ($size === false) {
            $hashDir = basename(dirname(str_replace('\\', '/', $log->file_path)));
            $filename = basename(str_replace('\\', '/', $log->file_path));
            $fallback = storage_path("app/{$hashDir}/{$filename}");
            $size = @filesize($fallback);
        }

        return $size !== false ? $size : null;
    }
}
