<?php

namespace App\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

class GameData extends Data
{
    public function __construct(
        public string $gameId,
        public Collection $deck,
        public Collection $entries,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            gameId: $data['gameId'],
            deck: collect(),
            entries: collect(),
        );
    }
}
