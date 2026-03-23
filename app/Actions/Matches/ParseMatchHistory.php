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

        return [
            'wins' => (int) ($match['GameWins'] ?? 0),
            'losses' => (int) ($match['GameLosses'] ?? 0),
        ];
    }
}
