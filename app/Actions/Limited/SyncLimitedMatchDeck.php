<?php

namespace App\Actions\Limited;

use App\Actions\Matches\DetermineMatchDeck;
use App\Events\DeckLinkedToMatch;
use App\Models\MtgoMatch;

class SyncLimitedMatchDeck
{
    /**
     * Record a registered-deck snapshot for a limited match and keep the
     * league's deck_version_id current.
     *
     * The snapshot is recorded for every limited match, regardless of whether
     * it already has a deck version. When the match's version was already
     * resolved by signature (its played deck matches an existing DeckVersion),
     * sync the league's deck_version_id to it: this is the mechanism that
     * actually keeps a limited league's deck_version_id current match to
     * match, which AssignLeague's step 2.2 relies on.
     *
     * Only mint a synthetic version from the registered snapshot when the
     * match still has no version at all: the registered deck never exists as
     * MTGO XML, so the signature lookup missed. Never mint unconditionally
     * alongside an already-resolved match: registered and played decks can
     * differ (sideboarding, misreported quantities), and minting from the
     * registered snapshot on top of a signature match would create a
     * spurious extra DeckVersion.
     *
     * Non-limited and league-less matches are a no-op, so every caller can
     * hand over any match it has just touched.
     */
    public static function run(MtgoMatch $match): void
    {
        $league = $match->league;

        if (! $league || ! $league->kind->isLimited()) {
            return;
        }

        $snapshot = RecordRegisteredDeckSnapshot::run($match);

        if ($match->deck_version_id) {
            if ($league->deck_version_id !== $match->deck_version_id) {
                $league->update(['deck_version_id' => $match->deck_version_id]);
            }

            return;
        }

        if (! $snapshot) {
            return;
        }

        EnsureLimitedDeckVersion::run($league, $snapshot);
        DetermineMatchDeck::run($match);
        $match->refresh();

        if ($match->deck_version_id) {
            DeckLinkedToMatch::dispatch($match);
        }
    }
}
