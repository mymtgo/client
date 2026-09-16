<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript */
class MatchupSummaryData extends Data
{
    public function __construct(
        public int $archetypeId,
        public string $name,
        public ?string $colorIdentity,
        public int $winrate,
        public int $matches,
    ) {}
}
