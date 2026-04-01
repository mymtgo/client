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
        $games = self::splitIntoGames($entries);

        // Per-game extraction
        $cardsByGame = [];
        // Match-level aggregate (union of all games)
        $matchSeen = [];
        $cardsByPlayer = [];

        foreach ($players as $player) {
            $cardsByPlayer[$player] = [];
        }

        foreach ($games as $gameIndex => $gameEntries) {
            $gameCards = self::extractFromEntries($gameEntries, $players);
            $cardsByGame[$gameIndex] = $gameCards;

            // Merge into match-level aggregate
            foreach ($players as $player) {
                foreach ($gameCards[$player] ?? [] as $card) {
                    if (isset($matchSeen[$player][$card['mtgo_id']])) {
                        // Sum cast count from this game into existing match-level entry
                        $matchSeen[$player][$card['mtgo_id']] += $card['cast'];
                        $idx = array_search($card['mtgo_id'], array_column($cardsByPlayer[$player], 'mtgo_id'));
                        if ($idx !== false) {
                            $cardsByPlayer[$player][$idx]['cast'] += $card['cast'];
                        }

                        continue;
                    }

                    $matchSeen[$player][$card['mtgo_id']] = $card['cast'];
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

    /**
     * Split entries into per-game groups using game boundary detection.
     *
     * Mirrors ExtractGameResults::splitIntoGames logic.
     *
     * @return array<int, array<int, array{timestamp: string, message: string}>>
     */
    private static function splitIntoGames(array $entries): array
    {
        $games = [];
        $current = [];
        $gameEndSeen = false;

        foreach ($entries as $entry) {
            $msg = $entry['message'];

            if (preg_match('/wins the game|has conceded from the game|has lost connection to the game/', $msg)) {
                $gameEndSeen = true;
            }

            if ($gameEndSeen && (preg_match('/^@P\w+ rolled a \d/', $msg) || preg_match('/^@P@P\w+ joined the game/', $msg))) {
                $games[] = $current;
                $current = [];
                $gameEndSeen = false;
            }

            $current[] = $entry;
        }

        if (! empty($current)) {
            $games[] = $current;
        }

        return $games;
    }
}
