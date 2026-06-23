<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GameDeck;
use App\Models\MtgoMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameDeck>
 */
class GameDeckFactory extends Factory
{
    protected $model = GameDeck::class;

    public function definition(): array
    {
        return [
            'game_id' => Game::factory(['match_id' => MtgoMatch::factory()]),
            'is_opponent' => false,
            'deck_json' => [],
        ];
    }

    public function opponent(): static
    {
        return $this->state(fn () => ['is_opponent' => true]);
    }
}
