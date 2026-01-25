<?php

namespace App\Data\Front;

use App\Models\Game;
use Spatie\LaravelData\Data;

/** @typescript  */
class PlayerData extends Data
{
    public function __construct(
        public int $id,
        public string $username,
        public bool $isLocal,
        public bool $onPlay,
        public int $startingHandSize,
        public array $deck,
    ) {}

    public static function fromModel(\App\Models\Player $player): self
    {
        return new self(
            id: $player->id,
            username: $player->username,
            isLocal: (bool) $player->pivot?->is_local,
            onPlay: (bool) $player->pivot?->on_play,
            startingHandSize: (int) $player->pivot?->starting_hand_size ?: 0,
            deck: $player->pivot?->deck_json ?: [],
        );
    }
}
