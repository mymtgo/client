<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript */
final class DrawOddsTypeData extends Data
{
    public function __construct(
        public string $type,
        /** P(>=1 of this type among the next 5 draws), range 0..1 */
        public float $probability,
    ) {}
}
