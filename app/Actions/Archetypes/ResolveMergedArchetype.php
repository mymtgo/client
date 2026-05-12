<?php

namespace App\Actions\Archetypes;

use App\Models\Archetype;

class ResolveMergedArchetype
{
    /**
     * Resolve a detected archetype id and variant id, following one merge hop if applicable.
     *
     * If the archetype has been merged into a parent, returns the parent's id and the
     * parent's latest ArchetypeDeck id (ordered by last_synced_at DESC, created_at DESC).
     * Otherwise passes through the given ids unchanged.
     *
     * @return array{archetype_id: int, archetype_deck_id: int|null}
     */
    public static function run(int $archetypeId, ?int $variantId): array
    {
        $archetype = Archetype::query()->find($archetypeId);

        if ($archetype === null || $archetype->merged_into_id === null) {
            return [
                'archetype_id' => $archetypeId,
                'archetype_deck_id' => $variantId,
            ];
        }

        $parentId = $archetype->merged_into_id;

        $latestVariantId = Archetype::query()
            ->whereKey($parentId)
            ->with(['decks' => fn ($q) => $q
                ->orderByDesc('last_synced_at')
                ->orderByDesc('created_at')
                ->limit(1),
            ])
            ->first()
            ?->decks
            ?->first()
            ?->id;

        return [
            'archetype_id' => $parentId,
            'archetype_deck_id' => $latestVariantId,
        ];
    }
}
