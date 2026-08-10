<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript  */
class SidedOutCardData extends Data
{
    public function __construct(
        public string $oracleId,
        public string $name,
        public ?string $image,
        public int $sidedOutGames,
    ) {}
}
