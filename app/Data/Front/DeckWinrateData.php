<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript  */
final class DeckWinrateData extends Data
{
    public function __construct(
        public int $wins,
        public int $games,
        public float $rate,
    ) {}
}
