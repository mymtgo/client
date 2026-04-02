<?php

namespace App\Data\Front;

use App\Models\Archetype;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

/** @typescript  */
class ArchetypeData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $format,
        public ?string $colorIdentity,
        public ?Carbon $decklistDownloadedAt,
        public bool $hasDecklist,
        public bool $manual,
    ) {}

    public static function fromModel(Archetype $archetype): self
    {
        return new self(
            id: $archetype->id,
            name: $archetype->name,
            format: $archetype->format,
            colorIdentity: $archetype->color_identity,
            decklistDownloadedAt: $archetype->decklist_downloaded_at,
            hasDecklist: $archetype->decklist_downloaded_at !== null,
            manual: $archetype->manual,
        );
    }
}
