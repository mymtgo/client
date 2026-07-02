<?php

namespace App\Data\ProjectedMatch;

use Spatie\LaravelData\Data;

/**
 * `mtgo_player_id` is the stable, rename-proof opponent key; `username` is
 * display only. Null player id on 0.x imports (contract identity rules).
 */
class OpponentData extends Data
{
    public function __construct(
        public ?int $mtgo_player_id,
        public ?string $username,
    ) {}
}
