<?php

namespace App\Actions\Import;

use App\Actions\Matches\ExtractGameResults;

class ExtractCardsFromGameLog
{
    /**
     * Every per-card counter this extractor produces. Shared by the stats
     * pipeline (aggregation, card_game_stats columns, API payload).
     *
     * @var list<string>
     */
    public const COUNTER_FIELDS = [
        'cast', 'played', 'kicked', 'flashback', 'madness', 'evoked', 'warp',
        'free_cast', 'bargained', 'dashed', 'bestowed', 'replicated',
        'spectacle', 'rebound', 'escaped', 'ninjutsu', 'suspended', 'buyback',
        'disturb', 'foretold', 'retraced', 'mayhem', 'miracle', 'gifted',
        'casualty', 'activated',
    ];

    /**
     * Casting-method detection: counter field => needles matched (case-sensitive,
     * any hit) against the text after the card reference in a "casts" line.
     * Phrasings captured from real MTGO logs — casing is exactly what MTGO emits.
     *
     * @var array<string, list<string>>
     */
    private const CAST_SUFFIX_FLAGS = [
        'kicked' => ['with kicker', 'kicked '],
        'flashback' => ['Flashback'],
        'madness' => ['Madness'],
        'evoked' => ['evoke'],
        'warp' => ['with warp', 'for its warp cost'],
        'free_cast' => ['without paying its mana cost'],
        'bargained' => ['with Bargain'],
        'dashed' => ['with dash', 'for its dash cost'],
        'bestowed' => ['with Bestow', 'for its bestow cost'],
        'replicated' => ['with Replicate', '(Replicated'],
        'spectacle' => ['with Spectacle', 'for its spectacle cost'],
        'rebound' => ['with Rebound'],
        'escaped' => ['for its escape cost'],
        'ninjutsu' => ['with sneak'],
        'suspended' => ['with suspend'],
        'buyback' => ['with buyback'],
        'disturb' => ['with disturb'],
        'foretold' => ['foretell cost'],
        'retraced' => ['with Retrace'],
        'mayhem' => ['with Mayhem'],
        'miracle' => ['with Miracle'],
        'gifted' => ['with Gift'],
        'casualty' => ['with Casualty'],
    ];

    /**
     * Extract unique card names and CatalogIDs per player from parsed game log entries.
     *
     * Returns match-level aggregates (cards_by_player), per-game breakdowns (cards_by_game),
     * per-game metadata (game_meta), and per-game pre-game actions (pregame_actions).
     *
     * @param  array<int, array{timestamp: string, message: string}>  $entries
     * @return array{players: array<int, string>, cards_by_player: array<string, list<array<string, mixed>>>, cards_by_game: array<int, array<string, list<array<string, mixed>>>>, game_meta: array<int, array{dice_rolls: array<string, int>, mulligans: array<string, int>, turn_count: int|null}>, pregame_actions: array<int, array<string, array<int, array{mtgo_id: int, type: string}>>>}
     */
    public static function run(array $entries): array
    {
        $players = ExtractGameResults::detectPlayers($entries);
        $games = ExtractGameResults::splitIntoGames($entries);

        $aggregateFields = self::COUNTER_FIELDS;

        // Per-game extraction
        $cardsByGame = [];
        $gameMeta = [];
        $pregameActions = [];
        // Match-level aggregate (union of all games)
        $matchIndex = [];
        $cardsByPlayer = [];

        foreach ($players as $player) {
            $cardsByPlayer[$player] = [];
        }

        foreach ($games as $gameIndex => $gameEntries) {
            $gameCards = self::extractFromEntries($gameEntries, $players);
            $cardsByGame[$gameIndex] = $gameCards;
            $gameMeta[$gameIndex] = self::extractGameMeta($gameEntries, $players);
            $pregameActions[$gameIndex] = self::extractPregameActions($gameEntries, $players);

            foreach ($players as $player) {
                foreach ($gameCards[$player] ?? [] as $card) {
                    if (isset($matchIndex[$player][$card['mtgo_id']])) {
                        $idx = $matchIndex[$player][$card['mtgo_id']];
                        foreach ($aggregateFields as $field) {
                            $cardsByPlayer[$player][$idx][$field] += $card[$field];
                        }

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
            'game_meta' => $gameMeta,
            'pregame_actions' => $pregameActions,
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
        $counterFields = self::COUNTER_FIELDS;
        $counts = [];

        foreach ($players as $player) {
            $cardsByPlayer[$player] = [];
            $counts[$player] = [];
        }

        foreach ($entries as $entry) {
            $msg = $entry['message'];

            foreach ($players as $player) {
                if (! str_contains($msg, '@P'.$player)) {
                    continue;
                }

                $owned = self::extractOwnedCards($msg, $player);

                foreach ($owned as $card) {
                    foreach ($counterFields as $field) {
                        if ($card[$field]) {
                            $counts[$player][$card['mtgo_id']][$field] = ($counts[$player][$card['mtgo_id']][$field] ?? 0) + 1;
                        }
                    }

                    if (isset($seen[$player][$card['mtgo_id']])) {
                        continue;
                    }

                    $seen[$player][$card['mtgo_id']] = true;
                    $cardsByPlayer[$player][] = [
                        'mtgo_id' => $card['mtgo_id'],
                        'name' => $card['name'],
                        ...array_fill_keys(self::COUNTER_FIELDS, 0),
                    ];
                }
            }
        }

        // Attach accumulated counts to each card entry
        foreach ($players as $player) {
            foreach ($cardsByPlayer[$player] as $idx => $card) {
                foreach ($counterFields as $field) {
                    $cardsByPlayer[$player][$idx][$field] = $counts[$player][$card['mtgo_id']][$field] ?? 0;
                }
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
        // The cast card is the player's. Capture rest of line for casting-method detection.
        if (preg_match('/@P'.$qp.' casts @\[([^@]+)@:(\d+),\d+:@\](.*)/', $msg, $m)) {
            $suffix = $m[3];

            $flags = ['cast'];
            foreach (self::CAST_SUFFIX_FLAGS as $field => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($suffix, $needle)) {
                        $flags[] = $field;

                        break;
                    }
                }
            }

            return [self::card($m[1], $m[2], $flags)];
        }

        // "@PPlayer plays @[Card]."
        if (preg_match('/@P'.$qp.' plays @\[([^@]+)@:(\d+),\d+:@\]/', $msg, $m)) {
            return [self::card($m[1], $m[2], ['played'])];
        }

        // "@PPlayer activates Ninjutsu ability of @[Card]." — ninjutsu puts the
        // creature onto the battlefield without a cast line.
        if (preg_match('/@P'.$qp.' activates Ninjutsu ability of @\[([^@]+)@:(\d+),\d+:@\]/', $msg, $m)) {
            return [self::card($m[1], $m[2], ['ninjutsu'])];
        }

        // "@PPlayer activates an ability of @[Card]..."
        if (preg_match('/@P'.$qp.' activates an ability of @\[([^@]+)@:(\d+),\d+:@\]/', $msg, $m)) {
            return [self::card($m[1], $m[2], ['activated'])];
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

        // "@PPlayer names X for @[Card]." (Pithing Needle, Disruptor Flute, etc.)
        if (preg_match('/@P'.$qp.' names .+ for @\[([^@]+)@:(\d+),\d+:@\]/', $msg, $m)) {
            return [self::card($m[1], $m[2])];
        }

        // ---------------------------------------------------------------
        // SKIP — patterns where the card belongs to the OTHER player
        // or ownership is ambiguous. We return nothing.
        // ---------------------------------------------------------------

        // "@PPlayer removes N counters from @[Card]" — combat damage to planeswalkers
        //   logs the attacker as removing loyalty from the defender's planeswalker
        // "@PPlayer exiles @[Card1] with @[Card2]" — opponent ability resolution
        //   (e.g. Subtlety ETB) logs the affected player with the opponent's ability source;
        //   also wrong under Mindslaver where the controller uses the opponent's cards
        // "@PPlayer returns @[Card1] with @[Card2]" — same as exiles-with
        // "@PPlayer draws a card with @[Card]" — ability source often opponent's
        // "@PPlayer is being attacked by @[Card]" — attacker is opponent's
        // "@PPlayer chooses to use @[Card]'s ability" — ambiguous ownership
        // "@PPlayer declines to use @[Card]'s ability" — ambiguous ownership
        // "@PPlayer chooses X for @[Card]" — ambiguous ownership

        return [];
    }

    /**
     * Extract per-game metadata: dice rolls, mulligan counts, and turn count.
     *
     * @param  array<int, array{timestamp: string, message: string}>  $gameEntries
     * @param  array<int, string>  $players
     * @return array{dice_rolls: array<string, int>, mulligans: array<string, int>, turn_count: int|null}
     */
    private static function extractGameMeta(array $gameEntries, array $players): array
    {
        $p = ExtractGameResults::PLAYER_PATTERN;
        $diceRolls = [];
        $mulliganCounts = [];
        $turnCount = null;

        foreach ($players as $player) {
            $mulliganCounts[$player] = 0;
        }

        foreach ($gameEntries as $entry) {
            $msg = $entry['message'];

            if (preg_match('/^@P('.$p.') rolled a (\d)/', $msg, $m)) {
                $diceRolls[$m[1]] = (int) $m[2];
            }

            if (preg_match('/^@P('.$p.') mulligans to/', $msg, $m)) {
                $mulliganCounts[$m[1]] = ($mulliganCounts[$m[1]] ?? 0) + 1;
            }

            if (preg_match('/^@PTurn (\d+):/', $msg, $m)) {
                $turn = (int) $m[1];
                if ($turnCount === null || $turn > $turnCount) {
                    $turnCount = $turn;
                }
            }
        }

        return [
            'dice_rolls' => $diceRolls,
            'mulligans' => $mulliganCounts,
            'turn_count' => $turnCount,
        ];
    }

    /**
     * Extract pre-game actions from a single game's entries.
     *
     * Pre-game actions occur between the last "begins the game with" message
     * and the first "@PTurn 1:" marker. Known patterns:
     * - Reveal from opening hand (Devourer of Destiny, Chancellor cycle)
     * - Put onto the battlefield (Gemstone Caverns, Leylines)
     *
     * @param  array<int, array{timestamp: string, message: string}>  $gameEntries
     * @param  array<int, string>  $players
     * @return array<string, array<int, array{mtgo_id: int, type: string}>>
     */
    private static function extractPregameActions(array $gameEntries, array $players): array
    {
        $p = ExtractGameResults::PLAYER_PATTERN;
        $actions = [];

        foreach ($players as $player) {
            $actions[$player] = [];
        }

        // Find the pre-game window: after last "begins the game with" and before first "@PTurn 1:"
        $pregameStart = null;
        $pregameEnd = null;

        foreach ($gameEntries as $i => $entry) {
            $msg = $entry['message'];

            if (preg_match('/begins the game with/', $msg)) {
                $pregameStart = $i + 1;
            }

            if ($pregameStart !== null && preg_match('/^@PTurn 1:/', $msg)) {
                $pregameEnd = $i;

                break;
            }
        }

        if ($pregameStart === null || $pregameEnd === null || $pregameStart >= $pregameEnd) {
            return $actions;
        }

        // Scan the pre-game window for actions
        for ($i = $pregameStart; $i < $pregameEnd; $i++) {
            $msg = $gameEntries[$i]['message'];

            foreach ($players as $player) {
                $qp = preg_quote($player, '/');

                // "@PPlayer reveals @[Card] from their opening hand." (Devourer of Destiny, Chancellors)
                if (preg_match('/@P'.$qp.' reveals @\[([^@]+)@:(\d+),\d+:@\] from their opening hand/', $msg, $m)) {
                    $actions[$player][] = [
                        'mtgo_id' => (int) $m[2] >> 1,
                        'type' => 'revealed',
                    ];
                }

                // "@PPlayer puts @[Card] onto the battlefield." (Gemstone Caverns, Leylines)
                if (preg_match('/@P'.$qp.' puts @\[([^@]+)@:(\d+),\d+:@\] onto the battlefield/', $msg, $m)) {
                    $actions[$player][] = [
                        'mtgo_id' => (int) $m[2] >> 1,
                        'type' => 'played',
                    ];
                }
            }
        }

        return $actions;
    }

    /**
     * Build a card entry from a regex match with the given counter flags set.
     *
     * @param  list<string>  $flags  COUNTER_FIELDS entries to mark true
     * @return array<string, mixed>
     */
    private static function card(string $name, string $rawId, array $flags = []): array
    {
        // Game log IDs are doubled CatalogIDs (front face = catId*2,
        // back face = catId*2+1). Right-shift to get the real CatalogID.
        $entry = [
            'mtgo_id' => (int) $rawId >> 1,
            'name' => $name,
            ...array_fill_keys(self::COUNTER_FIELDS, false),
        ];

        foreach ($flags as $flag) {
            $entry[$flag] = true;
        }

        return $entry;
    }
}
