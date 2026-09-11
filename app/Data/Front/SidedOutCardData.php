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
        public ?string $artCrop,
        public int $quantity,
        public int $sidedOutGames,
        /** How many of the wider player base's games cut this card. */
        public ?int $communitySidedOut = null,
        /** The games those counts are drawn from, as the rate's denominator. */
        public ?int $communityGames = null,
        /** communitySidedOut as a percentage, or null when the API has no row. */
        public ?int $communityRate = null,
        /** True when the field sample is large enough for its rate to be ordered on. */
        public bool $communityConfident = false,
        /** True when the panel should call this card, from whichever sample backs it. */
        public bool $recommended = false,
        /** Copies the player's guide says to take out, or null when the guide does not name this card. */
        public ?int $plannedQuantity = null,
        /** True when the guide names this card but the current deck version no longer contains it. */
        public bool $stale = false,
    ) {}
}
