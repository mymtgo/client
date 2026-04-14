<?php

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mtgo_id' => fake()->unique()->randomNumber(8),
            'started_at' => now(),
            'ended_at' => now()->addMinutes(10),
            'won' => null,
        ];
    }
}
