<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/**
 * Payload for the live draft notes window: which draft it is, which pick is
 * on the table, and every pick so far so the window can walk back through
 * them without a round trip. Still deliberately small; the window never
 * renders the pack.
 *
 * @typescript
 */
class DraftNotesData extends Data
{
    public function __construct(
        public int $draftId,
        public ?int $leagueId,
        public string $state,
        /** The newest pick, or null before the first pack lands. */
        public ?int $currentOrdinal,
        /** @var DraftNotePickData[] */
        public array $picks,
    ) {}
}
