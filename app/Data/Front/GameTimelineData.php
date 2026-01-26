<?php

namespace App\Data\Front;

use App\Data\GamePlayerData;
use App\Models\Game;
use App\Models\GameTimeline;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;

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
