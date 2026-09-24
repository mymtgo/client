<?php

namespace Database\Factories;

use App\Models\GameEvent;
use App\Models\LogInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameEvent>
 */
class GameEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'log_instance_id' => LogInstance::factory(),
            'session' => fake()->uuid(),
            'session_started_at' => now()->subMinutes(5),
            'seq' => fake()->unique()->numberBetween(1, 1_000_000),
            'source' => 'mtgo_sidecar',
            'type' => 'life_changed',
            'ts' => now(),
            'game_mtgo_id' => (string) fake()->randomNumber(9),
            'match_mtgo_id' => (string) fake()->randomNumber(9),
            'verified' => true,
            'data' => ['p' => 0, 'life' => 20],
            'ref' => null,
            'created_at' => now(),
        ];
    }
}
