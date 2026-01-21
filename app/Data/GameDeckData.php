<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class GameDeckData extends Data
{
    public function __construct(
        public string $mtgoId,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            gameId: $data['gameId'],
            entries: collect(),
            deck: collect(),
        );
    }
}
