<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueState;
use App\Models\League;

class CloseLeagueRun
{
    /**
     * Close a run the next match does not belong to: Complete when it holds
     * a full run of matches, Partial otherwise. The run length is the
     * league kind's round count unless the caller knows better (a sidecar
     * snapshot's total_matches).
     */
    public static function run(League $league, ?int $totalMatches = null): LeagueState
    {
        if ($league->matches()->count() >= ($totalMatches ?? $league->kind->roundCount())) {
            CompleteLeague::run($league);

            return LeagueState::Complete;
        }

        $league->update(['state' => LeagueState::Partial]);

        return LeagueState::Partial;
    }
}
