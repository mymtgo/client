<?php

namespace App\Data\Front;

use App\Data\GamePlayerData;
use App\Models\Game;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;

/** @typescript  */
class GameData extends Data
{
    public function __construct(
        public int $id,
        public Lazy|Collection $players,
        public Lazy|Collection $timeline,
    ) {}

    public static function fromModel(Game $game): self
    {
        return new self(
            id: $game->id,
            players: Lazy::whenLoaded('players', $game, fn () => PlayerData::collect($game->players)),
            timeline: Lazy::whenLoaded('timeline', $game, fn () => GameTimelineData::collect($game->timeline)),
        );
    }
}
