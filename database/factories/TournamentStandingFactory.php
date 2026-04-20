<?php

namespace Database\Factories;

use App\Models\Tournament;
use App\Models\TournamentStanding;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TournamentStanding> */
class TournamentStandingFactory extends Factory
{
    protected $model = TournamentStanding::class;

    public function definition(): array
    {
        return [
            'tournament_id' => Tournament::factory(),
            'round' => 1,
            'login_id' => fake()->unique()->randomNumber(6),
            'username' => fake()->userName(),
            'rank' => fake()->numberBetween(1, 64),
            'points' => 0,
            'wins' => 0,
            'losses' => 0,
            'draws' => 0,
            'is_local' => false,
        ];
    }
}
