<?php

namespace App\Actions\Matches;

class ParseMatchHistory
{
    /**
     * Attempt to find match results from MTGO's mtgo_game_history file.
     *
     * Looks up a match by its MTGO ID in the parsed history records.
     *
     * @return array{wins: int, losses: int}|null Returns null if not found or file unavailable
     */
    public static function findResult(string $mtgoId, ?string $path = null): ?array
    {
        $history = ParseGameHistory::run($path);

        if (empty($history)) {
            return null;
        }

        $match = collect($history)->first(
            fn (array $record) => (string) ($record['Id'] ?? '') === $mtgoId
        );

        if ($match === null) {
            return null;
        }

        $wins = (int) $match['GameWins'];
        $losses = (int) $match['GameLosses'];

        // MTGO seeds a 0-0 placeholder row in mtgo_game_history when a match
        // is joined. Treat that as "no result yet" — otherwise the stuck-match
        // watchdog will resolve an in-progress match as outcome=Unknown.
        if ($wins === 0 && $losses === 0) {
            return null;
        }

        return [
            'wins' => $wins,
            'losses' => $losses,
        ];
    }
}
