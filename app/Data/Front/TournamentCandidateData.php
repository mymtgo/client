<?php

namespace App\Data\Front;

use App\Models\Tournament;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

/** @typescript */
class TournamentCandidateData extends Data
{
    public function __construct(
        public int $id,
        public ?int $eventId,
        public ?string $type,
        public ?string $format,
        public ?Carbon $scheduledAt,
        public ?Carbon $startedAt,
        public ?int $maxRounds,
    ) {}

    public static function fromModel(Tournament $tournament): self
    {
        return new self(
            id: $tournament->id,
            eventId: $tournament->event_id,
            type: $tournament->type?->value,
            format: $tournament->format,
            scheduledAt: $tournament->scheduled_at,
            startedAt: $tournament->started_at,
            maxRounds: $tournament->max_rounds,
        );
    }
}
