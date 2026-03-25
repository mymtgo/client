<?php

namespace App\Actions\Matches;

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

            GameLog::firstOrCreate(
                ['match_token' => $token],
                ['file_path' => $file->getRealPath()],
            );
        }
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

            return GameLog::firstOrCreate(
                ['match_token' => $token],
                ['file_path' => $file->getRealPath()],
            );
        }

        return null;
    }
}
