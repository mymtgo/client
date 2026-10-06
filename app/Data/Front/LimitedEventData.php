<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript */
class LimitedEventData extends Data
{
    public function __construct(
        public int $id,
        public ?int $draftId,
        public string $title,
        public string $subtitle,
        public ?string $setCode,
        public ?string $setName,
        public string $kind,
        public string $state,
        public string $stateVariant,
        public ?string $startedAt,
        public ?string $startedAtHuman,
        public int $wins,
        public int $losses,
        public int $picksMade,
        public int $picksExpected,
        /** Boosters a sealed pool was opened from; null for a draft. */
        public ?int $packs,
        public bool $deckRegistered,
        public ?int $deckId,
        public ?string $coverArt,
        public ?int $seatIndex,
        public int $seatCount,
        public ?int $boosterCatalogId,
        public string $draftState,
    ) {}
}
