<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/**
 * One pick as the live notes window needs it: enough to label the pick, run
 * its countdown, and edit its note. Deliberately much smaller than
 * DraftPickData, which carries the pack, reservations and wheel data the
 * review page renders; this one ships forty-odd at a time on a one-second
 * poll.
 *
 * @typescript
 */
class DraftNotePickData extends Data
{
    public function __construct(
        public int $ordinal,
        public string $label,
        public int $cardsInPack,
        public ?string $deadlineAt,
        public ?int $pickedCatalogId,
        public ?string $pickedName,
        public ?string $note,
    ) {}
}
