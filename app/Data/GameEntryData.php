<?php

namespace App\Data;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

class GameEntryData extends Data
{
    public function __construct(
        public Carbon $timestamp,
        public Collection $players,
        public Collection $cards,

    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            timestamp: $data['timestamp'],
            players: collect($data['players']),
            cards: collect($data['cards']),
        );
    }
}
