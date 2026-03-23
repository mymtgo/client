<?php

namespace App\Actions\Matches;

use App\Models\Game;
use App\Models\GameLog;
use Carbon\Carbon;

class GetGameLogEntries
{
    /**
     * Get cleaned, display-ready game log entries for a specific game.
     *
     * Filters the match's decoded log entries to the game's time window
     * and cleans up MTGO formatting (@P prefixes, card reference markup).
     *
     * @return array<int, array{timestamp: string, message: string}>
     */
    public static function run(Game $game): array
    {
        $match = $game->match;

        if (! $match || ! $game->started_at || ! $game->ended_at) {
            return [];
        }

        $gameLog = GameLog::where('match_token', $match->token)->first();

        if (! $gameLog) {
            return [];
        }

        $entries = $gameLog->decoded_entries ?? [];

        if (empty($entries)) {
            return [];
        }

        $gameStart = $game->started_at->timestamp;
        $gameEnd = $game->ended_at->timestamp;

        return collect($entries)
            ->filter(function ($entry) use ($gameStart, $gameEnd) {
                $ts = Carbon::parse($entry['timestamp'])->timestamp;

                return $ts >= $gameStart - 5 && $ts <= $gameEnd + 5;
            })
            ->map(fn ($entry) => [
                'timestamp' => Carbon::parse($entry['timestamp'])->format('H:i:s'),
                'message' => self::cleanMessage($entry['message']),
            ])
            ->values()
            ->all();
    }

    /**
     * Clean up raw game log message for display.
     */
    private static function cleanMessage(string $message): string
    {
        // Remove @P@P (join messages) and @P (regular player prefix)
        $message = preg_replace('/^@P@P/', '', $message);
        $message = preg_replace('/^@P/', '', $message);
        $message = str_replace('@P', '', $message);

        // Clean card references: @[Card Name@:catalogId,instanceId:@] → Card Name
        $message = preg_replace('/@\[([^@]+)@:[^]]+@\]/', '$1', $message);

        return $message;
    }
}
