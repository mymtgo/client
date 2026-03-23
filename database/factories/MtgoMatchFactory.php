<?php

namespace Database\Factories;

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\MtgoMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MtgoMatch>
 */
class MtgoMatchFactory extends Factory
{
    protected $model = MtgoMatch::class;

    public function definition(): array
    {
        return [
            'mtgo_id' => fake()->unique()->randomNumber(8),
            'token' => fake()->uuid(),
            'format' => 'CStandard',
            'match_type' => 'Constructed',
            'state' => MatchState::Complete,
            'outcome' => MatchOutcome::Win,
            'games_won' => 2,
            'games_lost' => 1,
            'started_at' => now(),
            'ended_at' => now()->addMinutes(30),
        ];
    }

    public function won(): static
    {
        return $this->state(fn () => ['outcome' => MatchOutcome::Win, 'games_won' => 2, 'games_lost' => 1]);
    }

    public function lost(): static
    {
        return $this->state(fn () => ['outcome' => MatchOutcome::Loss, 'games_won' => 1, 'games_lost' => 2]);
    }
}
