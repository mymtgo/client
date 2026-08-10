<?php

namespace App\Actions\Overlay;

use App\Data\Front\ArchetypeNoteData;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckArchetypeNote;

class GetArchetypeNotes
{
    /**
     * Notes for this archetype, split into the current deck's own plan and
     * whatever the player wrote while piloting anything else.
     *
     * @return array{current: array<int, ArchetypeNoteData>, other: array<int, ArchetypeNoteData>}
     */
    public static function run(Deck $deck, Archetype $archetype): array
    {
        $notes = DeckArchetypeNote::query()
            ->where('archetype_id', $archetype->id)
            ->with('deck')
            ->latest('created_at')
            ->latest('id')
            ->get();

        return [
            'current' => $notes
                ->where('deck_id', $deck->id)
                ->map(fn (DeckArchetypeNote $note) => ArchetypeNoteData::fromModel($note))
                ->values()
                ->all(),
            'other' => $notes
                ->where('deck_id', '!=', $deck->id)
                ->map(fn (DeckArchetypeNote $note) => ArchetypeNoteData::fromModel($note))
                ->values()
                ->all(),
        ];
    }
}
