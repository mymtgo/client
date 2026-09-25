<?php

namespace App\Actions\Matches;

use App\Actions\Leagues\ResolveLeagueRunFromLog;
use App\Actions\Leagues\ResolveLeagueRunFromSidecar;
use App\Actions\Sidecar\AwaitSidecarAnswer;
use App\Models\MtgoMatch;

class AssignLeague
{
    /**
     * Assign a league to the match. Real leagues only; matches without a
     * League Token (or tournament matches) remain unattached.
     */
    public static function run(MtgoMatch $match, array $gameMeta): void
    {
        // Tournament matches are handled separately, with no league assignment.
        // Prefer the match column (stamped by AdvanceMatchState) because
        // $gameMeta['Description'] is unreliable for single-line logs where
        // ExtractKeyValueBlock can't split keys correctly.
        $isTournament = $match->tournament_event_id !== null
            || preg_match('/Tournament:\d+\s+Round:\d+/', $gameMeta['Description'] ?? '');

        if ($isTournament) {
            return;
        }

        if (empty($gameMeta['League Token'])) {
            return;
        }

        // A deferred deck is waited for first, before even the sidecar
        // league answer: minting now would give the league no deck, and a
        // league without one never syncs (spec 5.3).
        if ($match->deck_version_id === null && AwaitSidecarAnswer::run('match_deck', $match->created_at, $match->started_at)) {
            return;
        }

        if (ResolveLeagueRunFromSidecar::run($match, $gameMeta)) {
            return;
        }

        if (AwaitSidecarAnswer::run('league_run', $match->created_at, $match->started_at)) {
            return;
        }

        ResolveLeagueRunFromLog::run($match, $gameMeta);
    }
}
