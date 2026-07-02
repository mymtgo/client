<?php

namespace App\Actions\Compile\OutcomeResolvers;

use App\Actions\Matches\DetermineMatchResult;
use App\Data\ProjectedMatch\MatchData;
use App\Enums\MatchOutcome;
use Illuminate\Support\Collection;

/**
 * The local player conceded the match (ConcedeReqState → NotJoined*, both
 * casual and league variants). A match concession is a loss regardless of
 * the game tally at the time.
 */
final class ConcessionResolver implements OutcomeResolver
{
    public function attempt(MatchData $match, ?array $extracted, Collection $stateChanges): ?MatchOutcome
    {
        if (! DetermineMatchResult::localPlayerConceded($stateChanges)) {
            return null;
        }

        return MatchOutcome::Loss;
    }
}
