<?php

namespace App\Data\Front;

use App\Models\Archetype;
use Spatie\LaravelData\Data;

/** @typescript  */
class ArchetypeData extends Data
{
    public function __construct(
        public string $name,
        public string $format,
        public string $colorIdentity,
    ) {}

    public static function fromModel(Archetype $archetype): self
    {
        return new self(
            name: $archetype->name,
            format: $archetype->format,
            colorIdentity: $archetype->color_identity,
        );
    }
}
