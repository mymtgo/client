<?php

namespace App\Actions\Leagues;

use App\Actions\Sidecar\ReadMatchSnapshot;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\MtgoMatch;
use App\Sidecar\SidecarAuthorityFlags;

class CompleteLeagueFromSnapshot
{
    /**
     * Complete the match's league when its ended snapshot shows the run is
     * over (interim rule, spec 5.3). Covers the mid-league install, where
     * the local match count never reaches the round count.
     */
    public static function run(MtgoMatch $match): void
    {
        if (! SidecarAuthorityFlags::isOn('league_run')) {
            return;
        }

        $league = $match->league;

        if ($league === null || $league->state !== LeagueState::Active || $league->manual || $league->kind !== LeagueKind::Constructed) {
            return;
        }

        if (ReadMatchSnapshot::run($match, ReadMatchSnapshot::PHASE_ENDED)?->league?->showsRunFinished()) {
            CompleteLeague::run($league);
        }
    }
}
