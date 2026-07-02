<?php

namespace App\Data\ProjectedMatch;

use Spatie\LaravelData\Data;

/**
 * Per-card, per-game stats keyed on `oracle_id` (the cross-client card
 * identity). `cast` from 0.x is dropped — it duplicated `played`.
 */
class CardStatData extends Data
{
    public function __construct(
        public string $oracle_id,
        public bool $opponent,
        public int $quantity,
        public int $kept,
        public int $seen,
        public int $played,
        public ?bool $won,
        public bool $is_postboard,
        public bool $sided_out,
        public bool $pregame_revealed,
        public bool $pregame_played,
        public int $kicked,
        public int $flashback,
        public int $madness,
        public int $evoked,
        public int $activated,
    ) {}
}
