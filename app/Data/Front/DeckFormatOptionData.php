<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript */
class DeckFormatOptionData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public int $count,
    ) {}
}
