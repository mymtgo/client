<?php

namespace App\Actions\Matches;

use App\Enums\MatchState;
use App\Models\MtgoMatch;

class RelinkOrphanMatches
{
    /**
     * Re-attempt deck linking for matches that reached Complete without a deck_version_id.
     *
     * A match can be orphaned when its deck XML file lands after the Started → InProgress
     * transition — DetermineMatchDeck only fires once at that boundary and the match can
     * advance all the way to Complete with deck_version_id = null.
     *
     * RunPipeline calls this each tick so orphans re-link as soon as SyncDecks creates
     * the matching DeckVersion. The recency window caps the work for matches that will
     * never link (e.g. decks the user has since deleted).
     */
    public static function run(int $limit = 20, int $withinDays = 7): void
    {
        MtgoMatch::where('state', MatchState::Complete)
            ->whereNull('deck_version_id')
            ->where('ended_at', '>', now()->subDays($withinDays))
            ->orderByDesc('ended_at')
            ->limit($limit)
            ->get()
            ->each(fn (MtgoMatch $match) => DetermineMatchDeck::run($match));
    }
}
