<?php

namespace App\Data\Front;

use Carbon\Carbon;
use Spatie\LaravelData\Data;

/** @typescript  */
class SideboardGuideSummaryData extends Data
{
    public function __construct(
        public int $id,
        public int $archetypeId,
        public string $archetypeName,
        public ?string $archetypeColorIdentity,
        /** Total copies planned to bring in. */
        public int $cardsIn,
        /** Total copies planned to take out. */
        public int $cardsOut,
        public int $notesCount,
        public Carbon $updatedAt,
        /** Completed matches vs this archetype across every version of the deck. */
        public int $matches,
        /** "W - L", or null when the deck has never faced the archetype. */
        public ?string $matchRecord,
        public ?int $matchWinrate,
        public ?int $gameWinrate,
    ) {}
}
