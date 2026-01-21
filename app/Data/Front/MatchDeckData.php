<?php

namespace App\Data\Front;

use App\Models\MtgoMatch;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;

/** @typescript  */
class MatchDeckData extends Data
{
    public function __construct(
        public Lazy|DeckData $deck,
    ) {}

    public static function fromModel(MtgoMatch $match): self
    {
        return new self(
            deck: Lazy::whenLoaded('deck', $match)
        );
    }
}
