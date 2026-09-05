<?php

namespace App\Actions\SideboardGuides;

use App\Models\Archetype;
use App\Models\Deck;
use App\Models\SideboardGuide;

class EnsureSideboardGuide
{
    /**
     * The guide for this deck against this archetype, created empty if absent.
     *
     * Called from the create dialog and from the overlay note store, so a note
     * written mid-match against an unguided matchup leaves a guide behind for
     * the player to fill in later.
     */
    public static function run(Deck $deck, Archetype $archetype): SideboardGuide
    {
        return SideboardGuide::query()->firstOrCreate([
            'deck_id' => $deck->id,
            'archetype_id' => $archetype->id,
        ]);
    }
}
