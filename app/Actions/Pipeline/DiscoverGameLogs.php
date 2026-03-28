<?php

namespace App\Actions\Pipeline;

use App\Actions\Matches\ParseGameLogBinary;
use App\Enums\MatchState;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\Finder;

class DiscoverGameLogs
{
    /**
     * Scan the given directory for game log files and create
     * GameLog records for any that match active matches.
     */
    public static function run(?string $directory = null): void
    {
        $directory = $directory ?? app('mtgo')->getLogDataPath();

        if (! $directory || ! is_dir($directory)) {
            return;
        }

        $activeTokens = MtgoMatch::whereIn('state', [
            MatchState::Started,
            MatchState::InProgress,
            MatchState::Ended,
        ])->pluck('token')->flip();

        if ($activeTokens->isEmpty()) {
            return;
        }

        $finder = (new Finder)
            ->files()
            ->in($directory)
            ->name('*Match_GameLog*');

        foreach ($finder as $file) {
            $parts = explode('_', $file->getFilenameWithoutExtension());
            $token = end($parts);

            if (! $activeTokens->has($token)) {
                continue;
            }

            $gameLog = GameLog::firstOrCreate(
                ['match_token' => $token],
                ['file_path' => $file->getRealPath()],
            );

            if ($gameLog->wasRecentlyCreated) {
                self::decodeGameLog($gameLog);
            }
        }
    }

    /**
     * Discover ALL game log files in the directory, regardless of active match state.
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

    /**
     * Attempt to discover a specific game log by match token.
     * Used as inline fallback when the main discovery didn't find it.
     */
    public static function discoverForToken(string $token, ?string $directory = null): ?GameLog
    {
        $directory = $directory ?? app('mtgo')->getLogDataPath();

        if (! $directory || ! is_dir($directory)) {
            return null;
        }

        $finder = (new Finder)
            ->files()
            ->in($directory)
            ->name("*Match_GameLog_{$token}*");

        foreach ($finder as $file) {
            Log::channel('pipeline')->info("DiscoverGameLogs: inline fallback found game log for token={$token}");

            $gameLog = GameLog::firstOrCreate(
                ['match_token' => $token],
                ['file_path' => $file->getRealPath()],
            );

            if ($gameLog->wasRecentlyCreated) {
                self::decodeGameLog($gameLog);
            }

            return $gameLog;
        }

        return null;
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
