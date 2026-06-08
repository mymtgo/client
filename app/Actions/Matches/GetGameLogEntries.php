<?php

namespace App\Actions\Matches;

use App\Models\Game;
use Carbon\Carbon;

class GetGameLogEntries
{
    /**
     * Display-ready game-log entries for a single game.
     *
     * Sources entries from log_events MetaMessage rows (live matches).
     * Imported matches have no log_events; returns [].
     *
     * @return array<int, array{timestamp: string, message: string}>
     */
    public static function run(Game $game): array
    {
        $match = $game->match;

        if ($game->started_at === null || $game->ended_at === null) {
            return [];
        }

        $entries = ExtractMetaMessageEntries::run($match->token);

        if (empty($entries)) {
            return [];
        }

        $gameStart = $game->started_at->timestamp;
        $gameEnd = $game->ended_at->timestamp;

        return collect($entries)
            ->map(fn ($entry) => [
                'carbon' => Carbon::parse($entry['timestamp']),
                'message' => $entry['message'],
            ])
            ->filter(fn ($entry) => $entry['carbon']->timestamp >= $gameStart - 5
                && $entry['carbon']->timestamp <= $gameEnd + 5)
            ->map(fn ($entry) => [
                'timestamp' => $entry['carbon']->toLocal()->format('H:i:s'),
                'message' => self::cleanMessage($entry['message']),
            ])
            ->values()
            ->all();
    }

    private static function cleanMessage(string $message): string
    {
        $message = preg_replace('/^@P@P/', '', $message);
        $message = preg_replace('/^@P/', '', $message);
        $message = str_replace('@P', '', $message);
        $message = preg_replace('/@\[([^@]+)@:[^]]+@\]/', '$1', $message);

        return $message;
    }
}
