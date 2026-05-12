<?php

namespace App\Actions\Archetypes;

use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReassignArchetypeVariant
{
    public static function run(ArchetypeDeck $variant, Archetype $target): void
    {
        $source = $variant->archetype;

        if ($source === null) {
            throw new InvalidArgumentException('Variant has no parent archetype.');
        }

        if ($source->id === $target->id) {
            throw new InvalidArgumentException('Variant already belongs to this archetype.');
        }

        if ($source->format !== $target->format) {
            throw new InvalidArgumentException('Target archetype must share the same format.');
        }

        if ($target->is_fallback) {
            throw new InvalidArgumentException('Cannot reassign to a fallback archetype.');
        }

        if ($target->merged_into_id !== null) {
            throw new InvalidArgumentException('Target archetype is already merged.');
        }

        if ($source->merged_into_id !== null) {
            throw new InvalidArgumentException('Source archetype is already merged.');
        }

        DB::transaction(function () use ($variant, $source, $target): void {
            $variant->update(['archetype_id' => $target->id]);

            $remaining = $source->decks()->count();
            if ($remaining === 0) {
                MergeArchetype::run($source->fresh(), $target->fresh());
            }
        });
    }
}
