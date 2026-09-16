<?php

namespace App\Actions\Decks;

use App\Models\Archetype;
use App\Models\DeckVersion;

/**
 * Every deck version behind the archetype tabs on the deck listing. Starts
 * from the same scoped deck set as the sidebar and the archetype header, so
 * the tabs, the header record and the sidebar row all describe one deck set.
 */
class GetArchetypeDeckVersionIds
{
    /**
     * @return array<int, int>
     */
    public static function run(Archetype $archetype, ?string $format, bool $hideDeleted): array
    {
        $deckIds = BuildDeckSidebarOptions::scopedDecks($format, $hideDeleted)
            ->where('decks.archetype_id', $archetype->id)
            ->select('decks.id');

        return DeckVersion::query()
            ->whereIn('deck_id', $deckIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
