<?php

namespace App\Actions\Archetypes;

use App\Models\Archetype;
use InvalidArgumentException;

class MergeArchetype
{
    public static function run(Archetype $source, Archetype $parent): void
    {
        if ($source->id === $parent->id) {
            throw new InvalidArgumentException('Cannot merge an archetype into itself.');
        }

        if ($source->format !== $parent->format) {
            throw new InvalidArgumentException('Archetypes must share the same format to merge.');
        }

        if ($source->is_fallback || $parent->is_fallback) {
            throw new InvalidArgumentException('Fallback archetypes cannot participate in a merge.');
        }

        if ($source->merged_into_id !== null) {
            throw new InvalidArgumentException('Source archetype is already merged.');
        }

        if ($parent->merged_into_id !== null) {
            throw new InvalidArgumentException('Parent archetype is already merged; merge chains are not supported.');
        }

        $source->update(['merged_into_id' => $parent->id]);
    }
}
