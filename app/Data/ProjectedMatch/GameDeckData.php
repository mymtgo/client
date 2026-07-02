<?php

namespace App\Data\ProjectedMatch;

use Spatie\LaravelData\Data;

/**
 * A per-game deck reference — the base64 cardlist signature, carried
 * verbatim (worker maps it to `game_decks`).
 */
class GameDeckData extends Data
{
    public function __construct(
        public string $signature,
    ) {}
}
