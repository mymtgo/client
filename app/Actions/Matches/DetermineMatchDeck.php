<?php

namespace App\Actions\Matches;

use App\Actions\Sidecar\AwaitSidecarAnswer;
use App\Models\MtgoMatch;

class DetermineMatchDeck
{
    /**
     * Link the match to the deck version it was played with. The only
     * writer of deck_version_id for this path.
     *
     * The sidecar answers first when it has a snapshot. Without one, a live
     * match on a healthy sidecar is left unlinked for up to the deferral
     * window (RelinkOrphanMatches retries), then the log path decides as it
     * always has (spec 5.5, 6.2).
     */
    public static function run(MtgoMatch $match): void
    {
        if (ResolveMatchDeckFromSidecar::run($match)) {
            return;
        }

        if (AwaitSidecarAnswer::run('match_deck', $match->created_at, $match->started_at)) {
            return;
        }

        $result = ResolveMatchDeckFromLog::run($match);

        if ($result['found']) {
            $match->update(['deck_version_id' => $result['version']?->id]);
        }
    }
}
