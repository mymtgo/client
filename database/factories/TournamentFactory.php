<?php

namespace Database\Factories;

use App\Enums\TournamentState;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Tournament> */
class TournamentFactory extends Factory
{
    protected $model = Tournament::class;

    public function definition(): array
    {
        return [
            'token' => Str::uuid()->toString(),
            'name' => $this->faker->randomElement(['Modern Challenge', 'Legacy Challenge', 'Pauper Challenge', 'Vintage Challenge']),
            'format' => $this->faker->randomElement(['Modern', 'Legacy', 'Pauper', 'Vintage']),
            'state' => TournamentState::AwaitingPlayers,
            'started_at' => now(),
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'state' => TournamentState::RoundInProgress,
            'current_round' => 2,
            'max_rounds' => 7,
            'player_count' => 32,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'state' => TournamentState::Completed,
            'current_round' => 7,
            'max_rounds' => 7,
            'player_count' => 32,
            'ended_at' => now(),
        ]);
    }

    public function participated(): static
    {
        return $this->state(fn () => [
            'participated' => true,
        ]);
    }
}
