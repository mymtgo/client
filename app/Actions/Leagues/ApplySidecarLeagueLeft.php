<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueState;
use App\Models\GameEvent;
use App\Models\League;
use App\Sidecar\LeagueSnapshot;
use Illuminate\Support\Facades\Log;

class ApplySidecarLeagueLeft
{
    /**
     * Apply a sidecar league_left to the exact league it names (spec 5.4).
     * Dropped while matches remain, Complete when none do. Counters the SDK
     * could not read mean it cannot tell, so the log path decides.
     *
     * @return League|null the league changed, or null when nothing was
     */
    public static function run(GameEvent $event): ?League
    {
        $snapshot = LeagueSnapshot::fromArray($event->data['league'] ?? null);

        if ($snapshot?->matchesRemaining === null) {
            return null;
        }

        $league = FindSidecarLeagueCandidates::run($snapshot, [LeagueState::Active])->first();

        if ($league === null) {
            return null;
        }

        if ($league->event_id === null && $snapshot->eventId !== null) {
            $league->update(['event_id' => $snapshot->eventId]);
        }

        if ($snapshot->matchesRemaining > 0) {
            $league->update(['state' => LeagueState::Dropped, 'dropped_at' => $event->ts]);
        } else {
            CompleteLeague::run($league);
        }

        Log::channel('pipeline')->info("ApplySidecarLeagueLeft: league #{$league->id} left", [
            'matches_remaining' => $snapshot->matchesRemaining,
        ]);

        return $league;
    }
}
