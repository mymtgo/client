<?php

namespace App\Actions\Matches;

use App\Models\GameLog;
use Symfony\Component\Finder\Finder;

class EnsureGameLogForMatch
{
    /**
     * Return the decoded game-log entries for a match, decoding the on-disk
     * .dat file on demand when no decoded GameLog row exists yet.
     *
     * The .dat file MTGO writes per match is the durable source of game-log
     * data — log_events are pruned after a match completes, so regeneration
     * sources from here instead. Returns [] when the .dat is gone from disk.
     *
     * @return array<int, array{timestamp: string, message: string}>
     */
    public static function run(string $matchToken): array
    {
        $gameLog = GameLog::where('match_token', $matchToken)->first();

        if ($gameLog?->decoded_entries) {
            return $gameLog->decoded_entries;
        }

        $filePath = self::locateDatFile($matchToken);

        if ($filePath === null) {
            return $gameLog?->decoded_entries ?? [];
        }

        $gameLog ??= new GameLog(['match_token' => $matchToken]);
        $gameLog->file_path = $filePath;
        $gameLog->save();

        DecodeGameLog::run($gameLog);

        return $gameLog->fresh()->decoded_entries ?? [];
    }

    /**
     * Find the match's *Match_GameLog* .dat in the log data directory by token.
     * Filenames end with the match token after the final underscore, matching
     * the discovery convention in DiscoverGameLogsJob.
     */
    private static function locateDatFile(string $matchToken): ?string
    {
        $directory = app('mtgo')->getLogDataPath();

        if (! $directory || ! is_dir($directory)) {
            return null;
        }

        $finder = (new Finder)
            ->files()
            ->in($directory)
            ->name('*Match_GameLog*')
            ->ignoreUnreadableDirs();

        foreach ($finder as $file) {
            $parts = explode('_', $file->getFilenameWithoutExtension());

            if (end($parts) === $matchToken) {
                return $file->getRealPath();
            }
        }

        return null;
    }
}
