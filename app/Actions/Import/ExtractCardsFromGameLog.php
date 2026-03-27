<?php

namespace App\Actions\Import;

use App\Actions\Matches\ExtractGameResults;

class ExtractCardsFromGameLog
{
    /**
     * Extract unique card names and CatalogIDs per player from parsed game log entries.
     *
     * @param  array<int, array{timestamp: string, message: string}>  $entries
     * @return array{players: string[], cards_by_player: array<string, array<int, array{mtgo_id: int, name: string}>>}
     */
    public static function run(array $entries): array
    {
        $players = ExtractGameResults::detectPlayers($entries);
        $cardsByPlayer = [];

        foreach ($players as $player) {
            $cardsByPlayer[$player] = [];
        }

        $seen = [];

        foreach ($entries as $entry) {
            $msg = $entry['message'];

            foreach ($players as $player) {
                if (! str_contains($msg, '@P'.$player)) {
                    continue;
                }

                preg_match_all('/@\[([^@]+)@:(\d+),(\d+):@\]/', $msg, $matches, PREG_SET_ORDER);

                foreach ($matches as $m) {
                    $name = $m[1];
                    $mtgoId = (int) $m[2];

                    if (isset($seen[$player][$mtgoId])) {
                        continue;
                    }

                    $seen[$player][$mtgoId] = true;
                    $cardsByPlayer[$player][] = [
                        'mtgo_id' => $mtgoId,
                        'name' => $name,
                    ];
                }
            }
        }

        return [
            'players' => $players,
            'cards_by_player' => $cardsByPlayer,
        ];
    }
}
