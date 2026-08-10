<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript  */
class OverlayOpponentData extends Data
{
    public function __construct(
        public string $username,
        public int $previousMatches,
        public int $wins,
        public int $losses,
        public ?int $archetypeId,
        public ?string $archetypeName,
        public ?string $archetypeColors,
        public string $source,
        public bool $manual,
    ) {}
}
