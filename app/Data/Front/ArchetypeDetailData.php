<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript  */
class ArchetypeDetailData extends Data
{
    public function __construct(
        public ArchetypeData $archetype,
        /** @var CardData[]|null */
        public ?array $cards,
        public ?float $playingWinrate,
        public ?string $playingRecord,
        public ?float $facingWinrate,
        public ?string $facingRecord,
        public bool $isStale,
    ) {}
}
