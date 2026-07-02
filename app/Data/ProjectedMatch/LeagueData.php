<?php

namespace App\Data\ProjectedMatch;

use Spatie\LaravelData\Data;

/**
 * `token` is the per-season league token — it repeats across a season's
 * runs, so it groups matches and is never a match key.
 */
class LeagueData extends Data
{
    public function __construct(
        public string $token,
        public ?string $name,
        public ?string $format,
        public ?string $joined_at,
        public ?string $dropped_at,
    ) {}
}
