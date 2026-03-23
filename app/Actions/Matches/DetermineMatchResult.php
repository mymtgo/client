<?php

namespace App\Actions\Matches;

use App\Models\LogEvent;
use Illuminate\Support\Collection;

class DetermineMatchResult
{
    /**
     * Determine the final win/loss counts for a match, accounting for
     * early termination via local concession or opponent disconnect.
     *
     * Reports actual game counts — never inflates to the win threshold.
     * The `decided` flag indicates whether the match outcome is known.
     *
     * @param  array<int, bool>  $logResults  Game results from GetGameLog (true = win, false = loss)
     * @param  Collection<int, LogEvent>  $stateChanges  Match state change events
     * @param  string  $gameStructure  e.g. 'Modern', 'BO5', etc.
     * @param  bool  $matchScoreExists  Whether a match score was found in the log
     * @param  bool  $disconnectDetected  Whether a disconnect was detected
     * @return array{wins: int, losses: int, decided: bool}
     */
    public static function run(
        array $logResults,
        Collection $stateChanges,
        string $gameStructure = '',
        bool $matchScoreExists = false,
        bool $disconnectDetected = false,
    ): array {
        $wins = count(array_filter($logResults, fn ($r) => $r === true));
        $losses = count(array_filter($logResults, fn ($r) => $r === false));

        $winThreshold = ($wins >= 3 || $losses >= 3) ? 3 : 2;
        $thresholdMet = $wins >= $winThreshold || $losses >= $winThreshold;
        $conceded = static::localPlayerConceded($stateChanges);

        $decided = $thresholdMet || $conceded || $matchScoreExists || $disconnectDetected;

        return [
            'wins' => $wins,
            'losses' => $losses,
            'decided' => $decided,
        ];
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
}
