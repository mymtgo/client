<?php

namespace Database\Factories;

use App\Enums\LeagueState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\League>
 */
class LeagueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'token' => fake()->uuid(),
            'name' => 'League '.fake()->word(),
            'format' => 'CStandard',
            'phantom' => false,
            'deck_change_detected' => false,
            'state' => LeagueState::Active,
            'started_at' => now(),
        ];
    }

    public function phantom(): static
    {
        return $this->state(fn () => [
            'phantom' => true,
            'name' => 'Phantom League '.fake()->word(),
        ]);
    }

    public function complete(): static
    {
        return $this->state(fn () => [
            'state' => LeagueState::Complete,
        ]);
    }

    public function partial(): static
    {
        return $this->state(fn () => [
            'state' => LeagueState::Partial,
        ]);
    }
}
