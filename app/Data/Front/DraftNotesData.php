<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/**
 * Payload for the live draft notes window: which draft, which pick is on
 * the table, and the note attached to it. Deliberately tiny; the window
 * never renders the pack.
 *
 * @typescript
 */
class DraftNotesData extends Data
{
    public function __construct(
        public int $draftId,
        public ?int $leagueId,
        public string $state,
        public ?int $ordinal,
        public ?string $label,
        public ?int $cardsInPack,
        public ?string $deadlineAt,
        public ?int $pickedCatalogId,
        public ?string $pickedName,
        public ?string $note,
    ) {}
}
