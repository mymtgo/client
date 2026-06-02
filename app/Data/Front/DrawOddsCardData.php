<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript */
final class DrawOddsCardData extends Data
{
    public function __construct(
        public string $name,
        public string $type,
        public int $remaining,
        public int $total,
        public float $drawChance,
    ) {}
}
