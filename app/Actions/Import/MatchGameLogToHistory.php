<?php

namespace App\Actions\Import;

use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameLogBinary;
use Carbon\Carbon;

class MatchGameLogToHistory
{
    /**
     * Match history records to game log files by StartTime ± 5 minutes and opponent.
     *
     * @param  array<int, array>  $historyRecords  Parsed game history records
     * @param  string  $dataPath  MTGO data directory path
     * @return array<int, array{history_id: int, game_log_token: ?string, game_log_entries: ?array}>
     */
    public static function run(array $historyRecords, string $dataPath): array
    {
        $logIndex = self::buildLogIndex($dataPath);
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
     * Build an index of all game log files with their players and timestamps.
     *
     * @return array<int, array{token: string, first_timestamp: string, players: string[], entries: array}>
     */
    private static function buildLogIndex(string $dataPath): array
    {
        $pattern = $dataPath.'/Match_GameLog_*.dat';
        $files = glob($pattern);

        if (! $files) {
            return [];
        }

        $index = [];

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            $parsed = ParseGameLogBinary::run($raw);

            if (! $parsed || empty($parsed['entries'])) {
                continue;
            }

            $token = str_replace('Match_GameLog_', '', pathinfo($file, PATHINFO_FILENAME));
            $players = ExtractGameResults::detectPlayers($parsed['entries']);

            $index[] = [
                'token' => $token,
                'first_timestamp' => $parsed['entries'][0]['timestamp'],
                'players' => $players,
                'entries' => $parsed['entries'],
            ];
        }

        return $index;
    }
}
