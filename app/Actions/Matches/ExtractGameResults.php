<?php

namespace App\Actions\Matches;

class ExtractGameResults
{
    /**
     * Regex fragment matching an MTGO username.
     * MTGO allows: English letters, digits, underscores, hyphens (3-20 chars).
     */
    public const PLAYER_PATTERN = '[A-Za-z0-9_-]+';

    /**
     * Word-to-number mapping for starting hand sizes.
     */
    private const HAND_SIZE_MAP = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4,
        'five' => 5, 'six' => 6, 'seven' => 7,
    ];

    /**
     * Extract per-game results from decoded game log entries.
     *
     * The `games` array is the canonical per-game source of truth. Indices
     * are stable and match `game_index`. Consumers MUST NOT derive flat
     * `[true, false, ...]` arrays for per-game lookups — they are lossy
     * when intermediate games lack a winner or "chooses to play" line.
     *
     * @param  array<int, array{timestamp: string, message: string}>  $entries
     * @param  string  $localPlayer  The local player's username (without @P prefix)
     * @return array{games: array<int, array<string, mixed>>, players: array<int, string>, match_score: ?array{0: int, 1: int}, match_decided: bool, starting_hands: array<int, array{player: string, starting_hand: int}>}
     */
    public static function run(array $entries, string $localPlayer): array
    {
        $players = self::detectPlayers($entries);
        $rawGames = self::splitIntoGames($entries);

        $games = [];
        $startingHands = [];

        foreach ($rawGames as $index => $gameEntries) {
            $game = self::analyzeGame($gameEntries, $index, $players);
            $games[] = $game;

            foreach ($game['starting_hands'] as $player => $handSize) {
                $startingHands[] = [
                    'player' => $player,
                    'starting_hand' => $handSize,
                ];
            }
        }

        $forfeit = self::detectTrailingForfeit($entries, $games, $players, $localPlayer);
        if ($forfeit !== null) {
            $games[] = $forfeit;
        }

        return [
            'games' => $games,
            'players' => $players,
            'match_score' => self::extractMatchScore($entries, $localPlayer),
            'match_decided' => self::hasMatchWinLine($entries),
            'starting_hands' => $startingHands,
        ];
    }

    /**
     * Split entries into per-game groups.
     *
     * Game boundaries are detected by roll events appearing after a game-end signal.
     * The observed sequence at each boundary is: rolls → @P@P joins → chooses to play → begins game.
     *
     * @return array<int, array<int, array{timestamp: string, message: string}>>
     */
    public static function splitIntoGames(array $entries): array
    {
        $games = [];
        $current = [];
        $gameEndSeen = false;

        foreach ($entries as $entry) {
            $msg = $entry['message'];

            if (preg_match('/wins the game|has conceded from the game|has lost connection to the game/', $msg)) {
                $gameEndSeen = true;
            }

            if ($gameEndSeen && (preg_match('/^@P'.self::PLAYER_PATTERN.' rolled a \d/', $msg) || preg_match('/^@P@P'.self::PLAYER_PATTERN.' joined the game/', $msg))) {
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

    /**
     * Detect a deciding game forfeited between games.
     *
     * When an opponent drops during sideboarding, the aborted game produces only
     * a "has conceded" / "lost connection" line with no roll/join, so it is never
     * split into its own game and the swallowed line is ignored by analyzeGame's
     * winner guard. The match then looks tied (e.g. 1-1) when the drop in fact
     * decided it. This rebuilds that game: the dropping player loses it.
     *
     * Gated on the match being level — when a player is already ahead, a trailing
     * leave is just the loser exiting the match, not a forfeited deciding game.
     *
     * @param  array<int, array{timestamp: string, message: string}>  $entries
     * @param  array<int, array<string, mixed>>  $games
     * @param  array<int, string>  $players
     * @return array<string, mixed>|null
     */
    private static function detectTrailingForfeit(array $entries, array $games, array $players, string $localPlayer): ?array
    {
        if (empty($games) || end($games)['winner'] === null) {
            return null;
        }

        $wins = 0;
        $losses = 0;
        foreach ($games as $game) {
            $winner = $game['winner'] ?? null;
            if ($winner === null) {
                continue;
            }
            if ($winner === $localPlayer) {
                $wins++;
            } else {
                $losses++;
            }
        }

        if ($wins === 0 || $wins !== $losses) {
            return null;
        }

        $lastWinIndex = null;
        foreach ($entries as $index => $entry) {
            if (preg_match('/wins the game/', $entry['message'])) {
                $lastWinIndex = $index;
            }
        }

        if ($lastWinIndex === null) {
            return null;
        }

        $leaver = null;
        $endReason = null;
        $forfeitEntry = null;

        foreach (array_slice($entries, $lastWinIndex + 1, null, true) as $entry) {
            $msg = $entry['message'];

            // A new game actually started — normal splitting already handled it.
            if (preg_match('/^@P'.self::PLAYER_PATTERN.' rolled a \d/', $msg) || preg_match('/^@P@P'.self::PLAYER_PATTERN.' joined the game/', $msg)) {
                return null;
            }

            if (preg_match('/^@P('.self::PLAYER_PATTERN.') has conceded from the game/', $msg, $m)) {
                $leaver = $m[1];
                $endReason = 'concede';
                $forfeitEntry = $entry;
            } elseif (preg_match('/^@P('.self::PLAYER_PATTERN.') has lost connection to the game/', $msg, $m)) {
                $leaver = $m[1];
                $endReason = 'disconnect';
                $forfeitEntry = $entry;
            }
        }

        if ($leaver === null) {
            return null;
        }

        return [
            'game_index' => count($games),
            'winner' => self::otherPlayer($leaver, $players),
            'loser' => $leaver,
            'end_reason' => $endReason,
            'on_play' => null,
            'starting_hands' => [],
            'started_at' => $forfeitEntry['timestamp'] ?? null,
            'ended_at' => $forfeitEntry['timestamp'] ?? null,
        ];
    }

    /**
     * Detect the two player names from the entries.
     *
     * @return array<int, string>
     */
    public static function detectPlayers(array $entries): array
    {
        $players = [];

        foreach ($entries as $entry) {
            if (preg_match('/^@P@P('.self::PLAYER_PATTERN.') joined the game/', $entry['message'], $m)) {
                $players[$m[1]] = true;
            } elseif (preg_match('/^@P('.self::PLAYER_PATTERN.') rolled a \d/', $entry['message'], $m)) {
                $players[$m[1]] = true;
            }
        }

        return array_keys($players);
    }

    /**
     * Analyze a single game's entries.
     *
     * @return array{game_index: int, winner: ?string, loser: ?string, end_reason: string, on_play: ?string, starting_hands: array<string, int>, started_at: ?string, ended_at: ?string}
     */
    private static function analyzeGame(array $entries, int $index, array $players): array
    {
        $winner = null;
        $loser = null;
        $endReason = 'unknown';
        $onPlay = null;
        $startingHands = [];
        $startedAt = null;
        $endedAt = null;

        foreach ($entries as $entry) {
            $msg = $entry['message'];
            $ts = $entry['timestamp'];

            if ($startedAt === null) {
                $startedAt = $ts;
            }
            $endedAt = $ts;

            if (preg_match('/^@P('.self::PLAYER_PATTERN.') chooses to play first/', $msg, $m)) {
                $onPlay = $m[1];
            } elseif (preg_match('/^@P('.self::PLAYER_PATTERN.') chooses to play second/', $msg, $m)) {
                $onPlay = self::otherPlayer($m[1], $players);
            }

            if (preg_match('/^@P('.self::PLAYER_PATTERN.') begins the game with (\w+) cards? in hand/', $msg, $m)) {
                $handRaw = strtolower($m[2]);
                $handSize = ctype_digit($handRaw)
                    ? (int) $handRaw
                    : (self::HAND_SIZE_MAP[$handRaw] ?? null);

                if ($handSize !== null) {
                    $startingHands[$m[1]] = $handSize;
                }
            }

            if (preg_match('/^@P('.self::PLAYER_PATTERN.') wins the game/', $msg, $m)) {
                $winner = $m[1];
                $loser = self::otherPlayer($m[1], $players);
                $endReason = 'win';
            }

            if (preg_match('/^@P('.self::PLAYER_PATTERN.') has conceded from the game/', $msg, $m)) {
                if ($winner === null) {
                    $loser = $m[1];
                    $winner = self::otherPlayer($m[1], $players);
                    $endReason = 'concede';
                }
            }

            if (preg_match('/^@P('.self::PLAYER_PATTERN.') has lost connection to the game/', $msg, $m)) {
                if ($winner === null) {
                    $loser = $m[1];
                    $winner = self::otherPlayer($m[1], $players);
                    $endReason = 'disconnect';
                }
            }
        }

        return [
            'game_index' => $index,
            'winner' => $winner,
            'loser' => $loser,
            'end_reason' => $endReason,
            'on_play' => $onPlay,
            'starting_hands' => $startingHands,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
        ];
    }

    /**
     * Find the other player given one player name and the player list.
     */
    private static function otherPlayer(string $player, array $players): ?string
    {
        foreach ($players as $p) {
            if ($p !== $player) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Check if a definitive "wins the match" line exists in the entries.
     * "leads the match" is a mid-match score update, not a terminal signal.
     */
    private static function hasMatchWinLine(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (preg_match('/^@P'.self::PLAYER_PATTERN.' wins the match \d+-\d+/', $entry['message'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract match score from "leads the match X-Y" or "wins the match X-Y" lines.
     * Returns score as [localWins, opponentWins] or null if not found.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function extractMatchScore(array $entries, string $localPlayer): ?array
    {
        $lastScore = null;

        foreach ($entries as $entry) {
            if (preg_match('/^@P('.self::PLAYER_PATTERN.') (?:leads|wins) the match (\d+)-(\d+)/', $entry['message'], $m)) {
                $scorer = $m[1];
                $scorerWins = (int) $m[2];
                $scorerLosses = (int) $m[3];

                $lastScore = $scorer === $localPlayer
                    ? [$scorerWins, $scorerLosses]
                    : [$scorerLosses, $scorerWins];
            }

            if (preg_match('/^Match Tied (\d+)-(\d+)/', $entry['message'], $m)) {
                $lastScore = [(int) $m[1], (int) $m[2]];
            }
        }

        return $lastScore;
    }
}
