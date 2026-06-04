<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/** @typescript */
final class DrawOddsData extends Data
{
    public function __construct(
        /** @var DataCollection<int, DrawOddsCardData> */
        public DataCollection $cards,
        public int $librarySize,
        public int $liveLibraryCount,
    ) {}
}
