<?php

namespace App\Actions\Import;

use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameLogBinary;
use App\Models\GameLog;
use Carbon\Carbon;

class MatchGameLogToHistory
{
    /**
     * Match history records to game log files by StartTime ± 5 minutes and opponent.
     *
     * @param  array<int, array>  $historyRecords  Parsed game history records
     * @return array<int, array{history_id: int, game_log_token: ?string, game_log_entries: ?array}>
     */
    public static function run(array $historyRecords): array
    {
        $logIndex = self::buildLogIndex();
        $results = [];

        foreach ($historyRecords as $record) {
            $historyStart = Carbon::parse($record['StartTime']);
            $opponent = $record['Opponents'][0] ?? null;
            $match = null;

            if ($opponent) {
                foreach ($logIndex as $log) {
                    $logStart = Carbon::parse($log['first_timestamp']);
                    $timeDiff = abs($historyStart->diffInSeconds($logStart));

                    if ($timeDiff < 300 && in_array($opponent, $log['players'])) {
                        $match = $log;
                        break;
                    }
                }
            }

            $results[] = [
                'history_id' => $record['Id'],
                'game_log_token' => $match['token'] ?? null,
                'game_log_entries' => $match['entries'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * Build an index from game_logs DB records, parsing files that lack decoded entries.
     *
     * @return array<int, array{token: string, first_timestamp: string, players: string[], entries: array}>
     */
    private static function buildLogIndex(): array
    {
        $gameLogs = GameLog::whereDoesntHave('match')->get();

        $index = [];

        foreach ($gameLogs as $gameLog) {
            $entries = $gameLog->decoded_entries;

            if (! $entries) {
                if (! $gameLog->file_path || ! file_exists($gameLog->file_path)) {
                    continue;
                }

                $raw = file_get_contents($gameLog->file_path);
                $parsed = ParseGameLogBinary::run($raw);

                if (! $parsed || empty($parsed['entries'])) {
                    continue;
                }

                $entries = $parsed['entries'];
            }

            $players = ExtractGameResults::detectPlayers($entries);

            $index[] = [
                'token' => $gameLog->match_token,
                'first_timestamp' => $entries[0]['timestamp'],
                'players' => $players,
                'entries' => $entries,
            ];
        }

        return $index;
    }
}
