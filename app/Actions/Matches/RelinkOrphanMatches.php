<?php

namespace App\Actions\Matches;

use App\Enums\MatchState;
use App\Models\MtgoMatch;

class RelinkOrphanMatches
{
    /**
     * Re-attempt deck linking for matches that lack deck_version_id.
     *
     * Covers matches in InProgress, Ended, and Complete states. Recency is
     * scoped by started_at so pre-Complete matches (which may not have
     * ended_at yet) are still considered.
     */
    public static function run(int $limit = 20, int $withinDays = 7): void
    {
        MtgoMatch::whereIn('state', [
            MatchState::InProgress,
            MatchState::Ended,
            MatchState::Complete,
        ])
            ->whereNull('deck_version_id')
            ->where('started_at', '>', now()->subDays($withinDays))
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->each(fn (MtgoMatch $match) => DetermineMatchDeck::run($match));
    }
}
