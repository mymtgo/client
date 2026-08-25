<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript  */
class SideboardCardData extends Data
{
    public function __construct(
        public string $oracleId,
        public string $name,
        public ?string $type,
        public ?string $colorIdentity,
        public ?string $image,
        public ?string $artCrop,
        public int $quantity,
        public int $sidedInGames,
        public int $wins,
        public int $losses,
        public ?int $winrate,
        /** How many of the wider player base's games sided this card in. */
        public ?int $communitySidedIn = null,
        /** The games those counts are drawn from, as the rate's denominator. */
        public ?int $communityGames = null,
        /** communitySidedIn as a percentage, or null when the API has no row. */
        public ?int $communityRate = null,
    ) {}
}
