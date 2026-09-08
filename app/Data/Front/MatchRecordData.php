<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript */
class MatchRecordData extends Data
{
    public function __construct(
        public int $wins,
        public int $losses,
        public int $draws,
        public int $total,
        public int $winrate,
        public string $label,
    ) {}
}
