<?php

namespace App\Actions\Decks;

use App\Models\Deck;
use App\Models\MtgoMatch;

class FindLastPlayedDeck
{
    /**
     * The deck behind the most recently started match that has one, with its
     * cover loaded. Used when nothing is live, so the overlay and its settings
     * preview still have art to show.
     */
    public static function run(): ?Deck
    {
        /** @var MtgoMatch|null $match */
        $match = MtgoMatch::query()
            ->whereNotNull('deck_version_id')
            ->latest('started_at')
            ->with(['deck.cover', 'deck.archetype'])
            ->first();

        /** @var Deck|null */
        return $match?->getRelation('deck');
    }
}
