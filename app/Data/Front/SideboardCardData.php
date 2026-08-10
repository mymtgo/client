<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript  */
class SideboardCardData extends Data
{
    public function __construct(
        public string $oracleId,
        public string $name,
        public ?string $colorIdentity,
        public ?string $image,
        public int $quantity,
        public int $sidedInGames,
        public int $wins,
        public int $losses,
        public ?int $winrate,
    ) {}
}
