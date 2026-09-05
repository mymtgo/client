<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript  */
class SideboardGuideData extends Data
{
    /**
     * @param  array<int, SideboardCardData>  $sidedIn
     * @param  array<int, SidedOutCardData>  $sidedOut
     */
    public function __construct(
        public array $sidedIn,
        public array $sidedOut,
        public int $postboardGames,
        public string $postboardRecord,
        /** True when an authored plan with at least one card shaped this payload. */
        public bool $hasPlan = false,
    ) {}
}
