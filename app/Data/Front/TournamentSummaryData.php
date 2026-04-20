<?php

namespace App\Data\Front;

use App\Models\Tournament;
use Spatie\LaravelData\Data;

/** @typescript */
class TournamentSummaryData extends Data
{
    public function __construct(
        public int $id,
        public ?int $eventId,
        public ?string $format,
    ) {}

    public static function fromModel(Tournament $tournament): self
    {
        return new self(
            id: $tournament->id,
            eventId: $tournament->event_id,
            format: $tournament->format,
        );
    }
}
