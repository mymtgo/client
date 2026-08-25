<?php

namespace Database\Factories;

use App\Enums\DraftState;
use App\Models\Draft;
use App\Models\League;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Draft> */
class DraftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'league_id' => League::factory(),
            'draft_token' => fake()->uuid(),
            'mtgo_draft_id' => fake()->numberBetween(6_000_000, 7_000_000),
            'seat_count' => 8,
            'pack_size' => 14,
            'picks_expected' => 42,
            'state' => DraftState::Picking,
            'started_at' => now(),
        ];
    }

    public function finished(): static
    {
        return $this->state(fn () => ['state' => DraftState::Finished, 'ended_at' => now()]);
    }
}
