<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript */
class LimitedIndexKpisData extends Data
{
    public function __construct(
        public int $events,
        public int $drafts,
        public int $unlinked,
        public ?int $matchWinPct,
        public int $matchWins,
        public int $matchLosses,
        public ?float $avgWins,
        public ?float $avgLosses,
        public int $completedRuns,
        public ?string $mostDraftedSet,
        public int $mostDraftedCount,
        public ?int $avgPickSeconds,
        public ?int $indecisionPct,
    ) {}
}
