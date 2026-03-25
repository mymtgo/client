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
            'started_at' => now(),
            'ended_at' => now()->addMinutes(30),
        ];
    }

    public function won(): static
    {
        return $this->state(fn () => ['outcome' => MatchOutcome::Win]);
    }

    public function lost(): static
    {
        return $this->state(fn () => ['outcome' => MatchOutcome::Loss]);
    }

    public function started(): static
    {
        return $this->state(fn () => [
            'state' => MatchState::Started,
            'outcome' => null,
            'ended_at' => null,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'state' => MatchState::InProgress,
            'outcome' => null,
            'ended_at' => null,
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn () => [
            'state' => MatchState::Ended,
            'outcome' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'failed_at' => now(),
            'attempts' => 5,
        ]);
    }
}
