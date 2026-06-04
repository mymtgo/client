<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript */
final class DrawOddsCardData extends Data
{
    public function __construct(
        public ?int $mtgoId,
        public string $name,
        public string $type,
        public ?string $identity,
        public ?string $image,
        public int $remaining,
        public int $total,
    ) {}
}
