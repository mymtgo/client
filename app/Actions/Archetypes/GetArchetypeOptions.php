<?php

namespace App\Actions\Archetypes;

use App\Data\Front\ArchetypeData;
use App\Models\Archetype;
use Spatie\LaravelData\DataCollection;

class GetArchetypeOptions
{
    /**
     * Every archetype, name-ordered, shaped for a picker dropdown.
     *
     * Preloads the decklist-existence flag in the same query so the DTO never
     * has to probe per row.
     *
     * @return DataCollection<int, ArchetypeData>
     */
    public static function run(): DataCollection
    {
        return ArchetypeData::collect(
            Archetype::query()->withExists('decks')->orderBy('name')->get(),
            DataCollection::class,
        );
    }
}
