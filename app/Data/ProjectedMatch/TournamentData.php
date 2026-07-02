<?php

namespace App\Data\ProjectedMatch;

use Spatie\LaravelData\Data;

class TournamentData extends Data
{
    public function __construct(
        public ?int $mtgo_event_id,
        public ?int $round,
        public ?string $name,
    ) {}
}
