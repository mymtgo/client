<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class GamePlayerData extends Data
{
    public function __construct(
        public string $id,
        public string $username,
        public int $libraryCount,
        public int $handCount,
        public int $lifeTotal
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['Id'],
            username: $data['Name'],
            libraryCount: $data['LibraryCount'],
            handCount: $data['HandCount'],
            lifeTotal: $data['Life'],
        );
    }
}
