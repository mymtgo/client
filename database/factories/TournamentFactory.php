<?php

namespace Database\Factories;

use App\Models\Tournament;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tournament>
 */
class TournamentFactory extends Factory
{
    protected $model = Tournament::class;

    public function definition(): array
    {
        return [
            'mtgo_event_id' => $this->faker->unique()->numberBetween(12_000_000, 13_000_000),
            'token' => $this->faker->uuid(),
            'name' => 'Legacy Challenge 32',
            'format' => 'CLEGACY',
            'started_at' => now()->subHours(3),
            'name_synthesized' => false,
        ];
    }
}
