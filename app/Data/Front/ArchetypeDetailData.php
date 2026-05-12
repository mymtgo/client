<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript  */
class ArchetypeDetailData extends Data
{
    public function __construct(
        public ArchetypeData $archetype,
        /** @var ArchetypeDeckData[] */
        public array $decks,
        public bool $isStale,
        public ?ArchetypeData $mergedInto = null,
    ) {}
}
