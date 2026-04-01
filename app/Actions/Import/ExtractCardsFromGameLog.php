<?php

namespace App\Actions\Import;

use App\Actions\Matches\ExtractGameResults;

class ExtractCardsFromGameLog
{
    /**
     * Extract unique card names and CatalogIDs per player from parsed game log entries.
     *
     * Returns match-level aggregates (cards_by_player) and per-game breakdowns (cards_by_game).
     *
     * @param  array<int, array{timestamp: string, message: string}>  $entries
     * @return array{players: string[], cards_by_player: array<string, array<int, array{mtgo_id: int, name: string, cast: int}>>, cards_by_game: array<int, array<string, array<int, array{mtgo_id: int, name: string, cast: int}>>>}
     */
    public static function run(array $entries): array
    {
        $players = ExtractGameResults::detectPlayers($entries);
        $games = ExtractGameResults::splitIntoGames($entries);

        // Per-game extraction
        $cardsByGame = [];
        // Match-level aggregate (union of all games)
        $matchIndex = [];
        $cardsByPlayer = [];

        foreach ($players as $player) {
            $cardsByPlayer[$player] = [];
        }

        foreach ($games as $gameIndex => $gameEntries) {
            $gameCards = self::extractFromEntries($gameEntries, $players);
            $cardsByGame[$gameIndex] = $gameCards;

            foreach ($players as $player) {
                foreach ($gameCards[$player] ?? [] as $card) {
                    if (isset($matchIndex[$player][$card['mtgo_id']])) {
                        $cardsByPlayer[$player][$matchIndex[$player][$card['mtgo_id']]]['cast'] += $card['cast'];

                        continue;
                    }

                    $matchIndex[$player][$card['mtgo_id']] = count($cardsByPlayer[$player]);
                    $cardsByPlayer[$player][] = $card;
                }
            }
        }

        return [
            'players' => $players,
            'cards_by_player' => $cardsByPlayer,
            'cards_by_game' => $cardsByGame,
        ];
    }

    /**
     * Extract unique cards per player from a set of entries (single game or full match).
     *
     * @param  array<int, array{timestamp: string, message: string}>  $entries
     * @param  array<int, string>  $players
     * @return array<string, array<int, array{mtgo_id: int, name: string, cast: int}>>
     */
    private static function extractFromEntries(array $entries, array $players): array
    {
        $cardsByPlayer = [];
        $seen = [];
        // Track cast counts per player per mtgo_id
        $castCounts = [];

        foreach ($players as $player) {
            $cardsByPlayer[$player] = [];
            $castCounts[$player] = [];
        }

        foreach ($entries as $entry) {
            $msg = $entry['message'];

            foreach ($players as $player) {
                if (! str_contains($msg, '@P'.$player)) {
                    continue;
                }

                $quotedPlayer = preg_quote($player, '/');
                $isCast = (bool) preg_match('/@P'.$quotedPlayer.' (?:casts|plays) /', $msg);

                preg_match_all('/@\[([^@]+)@:(\d+),(\d+):@\]/', $msg, $matches, PREG_SET_ORDER);

                foreach ($matches as $m) {
                    $name = $m[1];
                    // Game log IDs are doubled CatalogIDs (front face = catId*2,
                    // back face = catId*2+1). Right-shift to get the real CatalogID.
                    $mtgoId = (int) $m[2] >> 1;

                    if ($isCast) {
                        $castCounts[$player][$mtgoId] = ($castCounts[$player][$mtgoId] ?? 0) + 1;
                    }

                    if (isset($seen[$player][$mtgoId])) {
                        continue;
                    }

                    $seen[$player][$mtgoId] = true;
                    $cardsByPlayer[$player][] = [
                        'mtgo_id' => $mtgoId,
                        'name' => $name,
                        'cast' => 0,
                    ];
                }
            }
        }

        // Attach accumulated cast counts to each card entry
        foreach ($players as $player) {
            foreach ($cardsByPlayer[$player] as $idx => $card) {
                $cardsByPlayer[$player][$idx]['cast'] = $castCounts[$player][$card['mtgo_id']] ?? 0;
            }
        }

        return $cardsByPlayer;
    }
}
