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

        dd($cardsByPlayer);

        return [
            'players' => $players,
            'cards_by_player' => $cardsByPlayer,
            'cards_by_game' => $cardsByGame,
        ];
    }

    /**
     * Extract unique cards per player from a set of entries (single game or full match).
     *
     * Each message pattern is handled explicitly to prevent cross-contamination
     * between players. Cards are only attributed to a player when we're certain
     * the card belongs to them.
     *
     * @param  array<int, array{timestamp: string, message: string}>  $entries
     * @param  array<int, string>  $players
     * @return array<string, array<int, array{mtgo_id: int, name: string, cast: int}>>
     */
    private static function extractFromEntries(array $entries, array $players): array
    {
        $cardsByPlayer = [];
        $seen = [];
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

                $owned = self::extractOwnedCards($msg, $player);

                foreach ($owned as $card) {
                    if ($card['cast']) {
                        $castCounts[$player][$card['mtgo_id']] = ($castCounts[$player][$card['mtgo_id']] ?? 0) + 1;
                    }

                    if (isset($seen[$player][$card['mtgo_id']])) {
                        continue;
                    }

                    $seen[$player][$card['mtgo_id']] = true;
                    $cardsByPlayer[$player][] = [
                        'mtgo_id' => $card['mtgo_id'],
                        'name' => $card['name'],
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
     * Given a game log message and a player name, return only the cards
     * that belong to that player. Each message pattern is handled individually.
     *
     * @return array<int, array{mtgo_id: int, name: string, cast: bool}>
     */
    private static function extractOwnedCards(string $msg, string $player): array
    {
        $qp = preg_quote($player, '/');

        // ---------------------------------------------------------------
        // PLAYER'S OWN CARDS — patterns where the referenced card(s)
        // definitively belong to the @P player.
        // ---------------------------------------------------------------

        // "@PPlayer casts @[Card]..."
        // The cast card is the player's. Ignore anything after "targeting".
        if (preg_match('/@P'.$qp.' casts @\[([^@]+)@:(\d+),\d+:@\]/', $msg, $m)) {
            return [self::card($m[1], $m[2], cast: true)];
        }

        // "@PPlayer plays @[Card]."
        if (preg_match('/@P'.$qp.' plays @\[([^@]+)@:(\d+),\d+:@\]/', $msg, $m)) {
            return [self::card($m[1], $m[2], cast: true)];
        }

        // "@PPlayer activates an ability of @[Card]..."
        if (preg_match('/@P'.$qp.' activates an ability of @\[([^@]+)@:(\d+),\d+:@\]/', $msg, $m)) {
            return [self::card($m[1], $m[2])];
        }

        // "@PPlayer puts a triggered ability from @[Card] onto the stack..."
        // The ability source belongs to the player. Cards after "targeting" do NOT.
        if (preg_match('/@P'.$qp.' puts a triggered ability from @\[([^@]+)@:(\d+),\d+:@\]/', $msg, $m)) {
            return [self::card($m[1], $m[2])];
        }

        // "@PPlayer reveals @[Card] from their opening hand."
        if (preg_match('/@P'.$qp.' reveals @\[([^@]+)@:(\d+),\d+:@\] from their opening hand/', $msg, $m)) {
            return [self::card($m[1], $m[2])];
        }

        // "@PPlayer reveals N cards with @[AbilitySource]: @[Card1], @[Card2], ..."
        // The player is revealing their OWN hand/library. The ability source
        // (after "with") may belong to the other player (e.g. Thought-Knot Seer).
        // Only extract the revealed card list after the colon.
        if (preg_match('/@P'.$qp.' reveals \d+ cards with @\[/', $msg)) {
            $cards = [];
            if (preg_match('/:\s*(.+)$/', $msg, $listMatch)) {
                preg_match_all('/@\[([^@]+)@:(\d+),\d+:@\]/', $listMatch[1], $all, PREG_SET_ORDER);
                foreach ($all as $m) {
                    $cards[] = self::card($m[1], $m[2]);
                }
            }

            return $cards;
        }

        // "@PPlayer reveals @[Card]." (single card, not from opening hand, not "with")
        // Player revealing their own card (e.g. from an ability).
        if (preg_match('/@P'.$qp.' reveals @\[([^@]+)@:(\d+),\d+:@\]/', $msg, $m)) {
            return [self::card($m[1], $m[2])];
        }

        // "@PPlayer discards @[Card]."
        if (preg_match('/@P'.$qp.' discards @\[([^@]+)@:(\d+),\d+:@\]/', $msg, $m)) {
            return [self::card($m[1], $m[2])];
        }

        // "@PPlayer puts @[Card] into their graveyard." (mill, surveil, etc.)
        if (preg_match('/@P'.$qp.' puts @\[([^@]+)@:(\d+),\d+:@\] into their graveyard/', $msg, $m)) {
            return [self::card($m[1], $m[2])];
        }

        // "@PPlayer's @[Card] creates..." (possessive — player owns the card)
        if (preg_match('/@P'.$qp.'\'s @\[([^@]+)@:(\d+),\d+:@\]/', $msg, $m)) {
            return [self::card($m[1], $m[2])];
        }

        // "@PPlayer removes N counters from @[Card]." (loyalty, +1/+1, etc.)
        if (preg_match('/@P'.$qp.' removes .+ from @\[([^@]+)@:(\d+),\d+:@\]/', $msg, $m)) {
            return [self::card($m[1], $m[2])];
        }

        // "@PPlayer names X for @[Card]." (Pithing Needle, Disruptor Flute, etc.)
        if (preg_match('/@P'.$qp.' names .+ for @\[([^@]+)@:(\d+),\d+:@\]/', $msg, $m)) {
            return [self::card($m[1], $m[2])];
        }

        // "@PPlayer exiles @[Card1] with @[Card2]'s ability."
        // Card2 (ability source) belongs to the player. Card1 might be the opponent's
        // permanent (e.g. Solitude exiling opponent's creature), so only take Card2.
        if (preg_match('/@P'.$qp.' exiles .+ with @\[([^@]+)@:(\d+),\d+:@\]/', $msg, $m)) {
            return [self::card($m[1], $m[2])];
        }

        // "@PPlayer returns @[Card1] ... with @[Card2]."
        // Card2 (the spell doing the returning) belongs to the player.
        // Card1 may be the opponent's permanent. Only take Card2.
        if (preg_match('/@P'.$qp.' returns .+ with @\[([^@]+)@:(\d+),\d+:@\]/', $msg, $m)) {
            return [self::card($m[1], $m[2])];
        }

        // ---------------------------------------------------------------
        // SKIP — patterns where the card belongs to the OTHER player
        // or ownership is ambiguous. We return nothing.
        // ---------------------------------------------------------------

        // "@PPlayer draws a card with @[Card]" — ability source often opponent's
        // "@PPlayer is being attacked by @[Card]" — attacker is opponent's
        // "@PPlayer chooses to use @[Card]'s ability" — ambiguous ownership
        // "@PPlayer declines to use @[Card]'s ability" — ambiguous ownership
        // "@PPlayer chooses X for @[Card]" — ambiguous ownership

        return [];
    }

    /**
     * Build a card entry from a regex match.
     *
     * @return array{mtgo_id: int, name: string, cast: bool}
     */
    private static function card(string $name, string $rawId, bool $cast = false): array
    {
        // Game log IDs are doubled CatalogIDs (front face = catId*2,
        // back face = catId*2+1). Right-shift to get the real CatalogID.
        return [
            'mtgo_id' => (int) $rawId >> 1,
            'name' => $name,
            'cast' => $cast,
        ];
    }
}
