<?php

namespace App\Actions\Archetypes;

use App\Models\Archetype;
use InvalidArgumentException;

class UnmergeArchetype
{
    public static function run(Archetype $source): void
    {
        if ($source->merged_into_id === null) {
            throw new InvalidArgumentException('Archetype is not merged.');
        }

        $source->update(['merged_into_id' => null]);
    }
}
