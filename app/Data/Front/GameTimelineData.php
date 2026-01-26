<?php

namespace App\Data\Front;

use App\Models\GameTimeline;
use Spatie\LaravelData\Data;

/** @typescript  */
class GameTimelineData extends Data
{
    public function __construct(
        public string $timestamp,
        public array $content,
    ) {}

    public static function fromModel(GameTimeline $timeline): self
    {
        return new self(
            timestamp: $timeline->timestamp,
            content: $timeline->content,
        );
    }
}
