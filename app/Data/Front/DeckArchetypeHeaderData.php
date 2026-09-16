<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript */
class DeckArchetypeHeaderData extends Data
{
    public function __construct(
        public ?ArchetypeData $archetype,
        public int $deckCount,
        public MatchRecordData $record,
        public ?MatchupSummaryData $bestMatchup,
        public ?MatchupSummaryData $worstMatchup,
    ) {}
}
