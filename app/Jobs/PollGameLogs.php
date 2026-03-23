<?php

namespace App\Jobs;

use App\Actions\Matches\ParseGameLogBinary;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\Finder;

class PollGameLogs implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 2;

    public function __construct() {}

    public function handle(): void
    {
        $this->discoverNewGameLogs();
        $this->parseActiveGameLogs();
    }

    /**
     * Scan the MTGO data directory for Match_GameLog .dat files and create
     * GameLog records for active matches that don't have one yet.
     */
    private function discoverNewGameLogs(): void
    {
        $basePath = Mtgo::getLogDataPath();

        if (empty($basePath) || ! is_dir($basePath)) {
            return;
        }

        $finder = Finder::create()
            ->files()
            ->in($basePath)
            ->name('*Match_GameLog*')
            ->ignoreUnreadableDirs();

        $activeStates = [
            MatchState::Started,
            MatchState::InProgress,
            MatchState::Ended,
        ];

        $activeTokens = MtgoMatch::query()
            ->whereIn('state', $activeStates)
            ->pluck('token')
            ->all();

        if (empty($activeTokens)) {
            return;
        }

        $activeTokenSet = array_flip($activeTokens);

        foreach ($finder as $file) {
            $nameParts = explode('_', $file->getFilename());
            $matchToken = pathinfo(last($nameParts), PATHINFO_FILENAME);

            if (! isset($activeTokenSet[$matchToken])) {
                continue;
            }

            GameLog::firstOrCreate([
                'match_token' => $matchToken,
            ], [
                'file_path' => $file->getRealPath(),
            ]);
        }
    }

    /**
     * For each GameLog belonging to an active match, check if the file has
     * grown and do a full re-parse if so.
     */
    private function parseActiveGameLogs(): void
    {
        $activeStates = [
            MatchState::Started,
            MatchState::InProgress,
            MatchState::Ended,
        ];

        $gameLogs = GameLog::query()
            ->whereHas('match', function ($query) use ($activeStates) {
                $query->whereIn('state', $activeStates);
            })
            ->get();

        foreach ($gameLogs as $log) {
            $this->parseGameLog($log);
        }
    }

    /**
     * Parse a single game log file if it has grown since last parse.
     */
    private function parseGameLog(GameLog $log): void
    {
        $fileSize = @filesize($log->file_path);

        if ($fileSize === false) {
            return;
        }

        // File hasn't grown — nothing to do
        if ($log->byte_offset >= $fileSize) {
            return;
        }

        $raw = @file_get_contents($log->file_path);

        if ($raw === false) {
            Log::channel('pipeline')->warning('PollGameLogs: unable to read file', [
                'file_path' => $log->file_path,
                'match_token' => $log->match_token,
            ]);

            return;
        }

        // Full re-parse (no byte_offset) so decoded_entries is a complete snapshot
        $parsed = ParseGameLogBinary::run($raw);

        if ($parsed === null) {
            return;
        }

        $log->update([
            'decoded_entries' => $parsed['entries'],
            'decoded_at' => now(),
            'byte_offset' => $parsed['byte_offset'],
            'decoded_version' => ParseGameLogBinary::VERSION,
        ]);
    }
}
