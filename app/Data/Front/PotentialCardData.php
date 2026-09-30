<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript */
final class PotentialCardData extends Data
{
    public function __construct(
        public ?int $mtgoId,
        public string $name,
        public string $type,
        public ?string $image,
        public ?string $artCrop,
        /** Copies the archetype's lists usually play, less those already revealed. */
        public int $quantity,
    ) {}
}
