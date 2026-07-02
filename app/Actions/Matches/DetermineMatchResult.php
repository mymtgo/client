<?php

namespace App\Actions\Matches;

use App\Models\LogEvent;
use Illuminate\Support\Collection;

class DetermineMatchResult
{
    /**
     * Determine the final win/loss counts for a match.
     *
     * Counts are derived from per-game data so they cannot be misaligned
     * by lossy flat arrays. When MTGO has emitted an authoritative match
     * score line ("leads the match X-Y" / "wins the match X-Y") it is
     * preferred over counted game winners.
     *
     * Reports actual game counts — never inflates to the win threshold.
     * The `decided` flag indicates whether the match outcome is known.
     *
     * @param  array<int, array{winner?: ?string, loser?: ?string}>  $games  Per-game data from ExtractGameResults
     * @param  string  $localPlayer  Local player username
     * @param  Collection<int, LogEvent>  $stateChanges  Match state change events
     * @param  array{0: int, 1: int}|null  $matchScore  MTGO-authoritative score [localWins, opponentWins]
     * @param  bool  $matchScoreExists  Whether a "wins the match" line was seen
     * @return array{wins: int, losses: int, decided: bool}
     */
    public static function run(
        array $games,
        string $localPlayer,
        Collection $stateChanges,
        ?array $matchScore = null,
        bool $matchScoreExists = false,
    ): array {
        [$wins, $losses] = self::countWinsAndLosses($games, $localPlayer, $matchScore);

        $winThreshold = ($wins >= 3 || $losses >= 3) ? 3 : 2;
        $thresholdMet = $wins >= $winThreshold || $losses >= $winThreshold;
        $conceded = static::localPlayerConceded($stateChanges);
        $matchCompleted = static::matchCompletedByServer($stateChanges);

        // A disconnect is deliberately NOT a deciding signal here — it is not
        // terminal (the player may reconnect). Disconnect-terminated matches
        // are resolved post-mortem by the stale-match reaper.
        $decided = $thresholdMet || $conceded || $matchCompleted || $matchScoreExists;

        return [
            'wins' => $wins,
            'losses' => $losses,
            'decided' => $decided,
        ];
    }

    /**
     * @param  array<int, array{winner?: ?string, loser?: ?string}>  $games
     * @param  array{0: int, 1: int}|null  $matchScore
     * @return array{0: int, 1: int}
     */
    private static function countWinsAndLosses(array $games, string $localPlayer, ?array $matchScore): array
    {
        if ($matchScore !== null) {
            return [$matchScore[0], $matchScore[1]];
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

        return [$wins, $losses];
    }

    /**
     * Detect whether the local player initiated a match concession.
     *
     * Works with both casual (Match*) and league (LeagueMatch*) state names:
     *   - Casual: MatchConcedeReqState to MatchNotJoinedEventUnderwayState
     *   - League: LeagueMatchConcedeReqState to LeagueMatchNotJoinedCatchAllState
     */
    public static function localPlayerConceded(Collection $stateChanges): bool
    {
        return $stateChanges->contains(
            fn (LogEvent $event) => preg_match('/ConcedeReqState to .+NotJoined/', $event->context ?? '')
        );
    }

    /**
     * Detect whether the server marked the match as completed.
     *
     * MatchCompletedState / MatchClosedState appear when the match ends
     * server-side — e.g. opponent forfeits during sideboarding.
     */
    public static function matchCompletedByServer(Collection $stateChanges): bool
    {
        return $stateChanges->contains(
            fn (LogEvent $event) => str_contains($event->context ?? '', 'MatchCompletedState')
                || str_contains($event->context ?? '', 'MatchClosedState')
        );
    }
}
