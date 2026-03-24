<?php

namespace App\Actions\Matches;

use Illuminate\Support\Facades\Log;

class ExtractGameResults
{
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
     * @param  array<int, array{timestamp: string, message: string}>  $entries
     * @param  string  $localPlayer  The local player's username (without @P prefix)
     * @return array{games: array, players: array, match_score: ?array, results: array, on_play: array, starting_hands: array, match_decided: bool}
     */
    public static function run(array $entries, string $localPlayer): array
    {
        $games = self::splitIntoGames($entries);
        $players = self::detectPlayers($entries);

        $gameResults = [];
        $results = [];
        $onPlay = [];
        $startingHands = [];

        foreach ($games as $index => $gameEntries) {
            $game = self::analyzeGame($gameEntries, $index, $localPlayer, $players);
            $gameResults[] = $game;

            if ($game['winner'] !== null) {
                $results[] = ($game['winner'] === $localPlayer);
            }

            if ($game['on_play'] !== null) {
                $onPlay[] = ($game['on_play'] === $localPlayer);
            }

            foreach ($game['starting_hands'] as $player => $handSize) {
                $startingHands[] = [
                    'player' => $player,
                    'starting_hand' => $handSize,
                ];
            }
        }

        // Extract match score from "leads the match" / "wins the match" lines
        $matchScore = self::extractMatchScore($entries, $localPlayer, $players);

        // Cross-check: if match score disagrees with counted results, trust MTGO's tally
        if ($matchScore !== null) {
            $countedWins = count(array_filter($results, fn ($r) => $r === true));
            $countedLosses = count(array_filter($results, fn ($r) => $r === false));

            if ($countedWins !== $matchScore[0] || $countedLosses !== $matchScore[1]) {
                Log::channel('pipeline')->warning('ExtractGameResults: match score cross-check failed', [
                    'counted' => [$countedWins, $countedLosses],
                    'mtgo_score' => $matchScore,
                    'local_player' => $localPlayer,
                ]);

                // Rebuild results from MTGO's authoritative score
                $results = array_merge(
                    array_fill(0, $matchScore[0], true),
                    array_fill(0, $matchScore[1], false),
                );
            }
        }

        return [
            'games' => $gameResults,
            'players' => $players,
            'match_score' => $matchScore,
            'match_decided' => self::hasMatchWinLine($entries),
            'results' => $results,
            'on_play' => $onPlay,
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
    private static function splitIntoGames(array $entries): array
    {
        $games = [];
        $current = [];
        $gameEndSeen = false;

        foreach ($entries as $entry) {
            $msg = $entry['message'];

            // Detect game-end signals
            if (preg_match('/wins the game|has conceded from the game|has lost connection to the game/', $msg)) {
                $gameEndSeen = true;
            }

            // Detect new game boundary: roll events or join events after a game end
            // Game 1 starts with rolls; games 2+ may start directly with @P@P joins
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

    /**
     * Detect the two player names from the entries.
     *
     * @return array<int, string>
     */
    public static function detectPlayers(array $entries): array
    {
        $players = [];

        foreach ($entries as $entry) {
            if (preg_match('/^@P@P(\w+) joined the game/', $entry['message'], $m)) {
                $players[$m[1]] = true;
            } elseif (preg_match('/^@P(\w+) rolled a \d/', $entry['message'], $m)) {
                $players[$m[1]] = true;
            }
        }

        return array_keys($players);
    }

    /**
     * Analyze a single game's entries.
     *
     * @return array{game_index: int, winner: ?string, loser: ?string, end_reason: string, on_play: ?string, starting_hands: array, started_at: ?string, ended_at: ?string}
     */
    private static function analyzeGame(array $entries, int $index, string $localPlayer, array $players): array
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

            // On play: "@P{player} chooses to play first/second."
            if (preg_match('/^@P(\w+) chooses to play first/', $msg, $m)) {
                $onPlay = $m[1];
            } elseif (preg_match('/^@P(\w+) chooses to play second/', $msg, $m)) {
                $onPlay = self::otherPlayer($m[1], $players);
            }

            // Starting hand: "@P{player} begins the game with {N} cards in hand."
            if (preg_match('/^@P(\w+) begins the game with (\w+) cards? in hand/', $msg, $m)) {
                $handRaw = strtolower($m[2]);
                $handSize = ctype_digit($handRaw)
                    ? (int) $handRaw
                    : (self::HAND_SIZE_MAP[$handRaw] ?? null);

                if ($handSize !== null) {
                    $startingHands[$m[1]] = $handSize;
                }
            }

            // Win: "@P{player} wins the game."
            if (preg_match('/^@P(\w+) wins the game/', $msg, $m)) {
                $winner = $m[1];
                $loser = self::otherPlayer($m[1], $players);
                $endReason = 'win';
            }

            // Concede: "@P{player} has conceded from the game."
            if (preg_match('/^@P(\w+) has conceded from the game/', $msg, $m)) {
                if ($winner === null) {
                    $loser = $m[1];
                    $winner = self::otherPlayer($m[1], $players);
                    $endReason = 'concede';
                }
            }

            // Disconnect: "@P{player} has lost connection to the game."
            if (preg_match('/^@P(\w+) has lost connection to the game/', $msg, $m)) {
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
            if (preg_match('/^@P\w+ wins the match \d+-\d+/', $entry['message'])) {
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
    private static function extractMatchScore(array $entries, string $localPlayer, array $players): ?array
    {
        $lastScore = null;

        foreach ($entries as $entry) {
            if (preg_match('/^@P(\w+) (?:leads|wins) the match (\d+)-(\d+)/', $entry['message'], $m)) {
                $scorer = $m[1];
                $scorerWins = (int) $m[2];
                $scorerLosses = (int) $m[3];

                if ($scorer === $localPlayer) {
                    $lastScore = [$scorerWins, $scorerLosses];
                } else {
                    $lastScore = [$scorerLosses, $scorerWins];
                }
            }
        }

        return $lastScore;
    }
}
