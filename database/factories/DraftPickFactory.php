<?php

namespace Database\Factories;

use App\Models\Draft;
use App\Models\DraftPick;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DraftPick> */
class DraftPickFactory extends Factory
{
    public function definition(): array
    {
        $ordinal = fake()->unique()->numberBetween(1, 42);

        return [
            'draft_id' => Draft::factory(),
            'ordinal' => $ordinal,
            'pack_number' => intdiv($ordinal - 1, 14) + 1,
            'pick_number' => (($ordinal - 1) % 14) + 1,
            'pack_id' => fake()->numberBetween(143_000_000, 144_000_000),
            'direction' => 0,
            'cards_available' => [fake()->numberBetween(150_000, 155_000)],
            'reservations' => [],
            'shown_at' => now(),
        ];
    }
}
