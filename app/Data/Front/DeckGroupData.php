<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/** @typescript  */
class DeckGroupData extends Data
{
    public function __construct(
        public ?ArchetypeData $archetype,
        public DeckGroupStatsData $stats,
        /** @var DataCollection<int, DeckData> */
        public DataCollection $decks,
    ) {}
}
