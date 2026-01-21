<?php

namespace App\Data\Front;

use App\Models\Game;
use Spatie\LaravelData\Data;

/** @typescript  */
class GameData extends Data
{
    public function __construct(
        public int $id,
    ) {}

    public static function fromModel(Game $game): self
    {
        return new self(
            id: $game->id,
        );
    }
}
