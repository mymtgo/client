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
        public ?string $format,
        public ?string $colorIdentity,
        public ?Carbon $decklistDownloadedAt,
        public bool $hasDecklist,
        public bool $manual,
        public bool $isFallback,
        public ?int $mergedIntoId = null,
    ) {}

    public static function fromModel(Archetype $archetype): self
    {
        return new self(
            id: $archetype->id,
            name: $archetype->name,
            format: $archetype->format,
            colorIdentity: $archetype->color_identity,
            decklistDownloadedAt: $archetype->decklist_downloaded_at,
            hasDecklist: self::hasDecklist($archetype),
            manual: $archetype->manual,
            isFallback: $archetype->is_fallback,
            mergedIntoId: $archetype->merged_into_id,
        );
    }

    /**
     * Read from what the caller already loaded, never probe the database.
     *
     * A per-row exists query here turned every archetype list into an N+1,
     * so list builders must preload with withExists('decks') and detail views
     * either do the same or load the decks relation itself.
     */
    private static function hasDecklist(Archetype $archetype): bool
    {
        if (isset($archetype->decks_exists)) {
            return (bool) $archetype->decks_exists;
        }

        if ($archetype->relationLoaded('decks')) {
            return $archetype->decks->isNotEmpty();
        }

        return false;
    }
}
