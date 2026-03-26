<?php

namespace Database\Factories;

use App\Models\Card;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mtgo_id' => $this->faker->unique()->numberBetween(1, 100000),
            'name' => $this->faker->word(),
        ];
    }

    /**
     * A stub card with only an mtgo_id — as created by CreateMissingCards before enrichment.
     */
    public function stub(): static
    {
        return $this->state(fn () => [
            'name' => null,
            'scryfall_id' => null,
            'oracle_id' => null,
            'type' => null,
            'sub_type' => null,
            'rarity' => null,
            'color_identity' => null,
            'image' => null,
        ]);
    }
}
