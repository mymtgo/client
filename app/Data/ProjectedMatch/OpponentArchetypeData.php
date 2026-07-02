<?php

namespace App\Data\ProjectedMatch;

use Spatie\LaravelData\Data;

/**
 * The local-live archetype guess for overlays. The worker re-derives the
 * authoritative archetype server-side.
 */
class OpponentArchetypeData extends Data
{
    public function __construct(
        public ?string $uuid,
        public ?string $name,
        public ?float $confidence,
    ) {}
}
