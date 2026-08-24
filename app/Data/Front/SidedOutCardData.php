<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript  */
class SidedOutCardData extends Data
{
    public function __construct(
        public string $oracleId,
        public string $name,
        public ?string $type,
        public ?string $image,
        public int $sidedOutGames,
        /** How many of the wider player base's games cut this card. */
        public ?int $communitySidedOut = null,
        /** The games those counts are drawn from, as the rate's denominator. */
        public ?int $communityGames = null,
        /** communitySidedOut as a percentage, or null when the API has no row. */
        public ?int $communityRate = null,
    ) {}
}
