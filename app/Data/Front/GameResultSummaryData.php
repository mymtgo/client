<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript  */
class GameResultSummaryData extends Data
{
    public function __construct(
        public string $result,
        public ?bool $onPlay,
    ) {}
}
