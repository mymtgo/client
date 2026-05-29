<?php

namespace Database\Factories;

use App\Enums\LeagueState;
use App\Models\League;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<League>
 */
class LeagueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'token' => fake()->uuid(),
            'name' => 'League '.fake()->word(),
            'format' => 'CStandard',
            'deck_change_detected' => false,
            'state' => LeagueState::Active,
            'started_at' => now(),
        ];
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

    public function manual(): static
    {
        return $this->state(fn () => [
            'manual' => true,
            'token' => 'manual_'.Str::random(24),
            'state' => LeagueState::Complete,
            'completed_at' => now(),
        ]);
    }
}
