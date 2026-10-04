<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueState;
use App\Models\League;

class CloseLeagueRun
{
    /**
     * Close a run the next match does not belong to: Complete when it holds
     * a full run of matches (the league kind's round count), Partial
     * otherwise.
     */
    public static function run(League $league): LeagueState
    {
        if ($league->matches()->count() >= $league->kind->roundCount()) {
            CompleteLeague::run($league);

            return LeagueState::Complete;
        }

        $league->update(['state' => LeagueState::Partial]);

        return LeagueState::Partial;
    }
}
