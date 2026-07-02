<?php

namespace App\Data\ProjectedMatch;

use Spatie\LaravelData\Data;

/**
 * One decoded MetaMessage timeline event (the local player's perspective —
 * MetaMessage bytes are per-perspective, see the contract notes).
 */
class TimelineEntryData extends Data
{
    public function __construct(
        public string $action,
        public ?string $timestamp,
        public ?string $player,
        public mixed $context,
    ) {}
}
