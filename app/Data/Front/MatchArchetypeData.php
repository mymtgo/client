<?php

namespace App\Data\Front;

use App\Models\MatchArchetype;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;

/** @typescript  */
class MatchArchetypeData extends Data
{
    public function __construct(
        public float $confidence,
        public Lazy|ArchetypeData $archetype,
    ) {}

    public static function fromModel(MatchArchetype $ma): self
    {
        return new self(
            confidence: $ma->confidence,
            archetype: Lazy::whenLoaded('archetype', $ma, fn () => ArchetypeData::from($ma->archetype)),
        );
    }
}
