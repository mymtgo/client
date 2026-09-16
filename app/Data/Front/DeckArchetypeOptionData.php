<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript */
class DeckArchetypeOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $colorIdentity,
        public ?string $format,
        public int $deckCount,
        public MatchRecordData $record,
    ) {}
}
