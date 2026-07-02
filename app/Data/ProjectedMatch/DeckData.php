<?php

namespace App\Data\ProjectedMatch;

use Spatie\LaravelData\Data;

/**
 * The local player's deck, inlined from the MTGO XML — the cloud owns
 * versioning, keyed on `modified_at` (never regresses).
 */
class DeckData extends Data
{
    public function __construct(
        public ?int $mtgo_id,
        public ?string $name,
        public ?string $format,
        public ?string $color_identity,
        public ?string $modified_at,
        public string $signature,
    ) {}
}
