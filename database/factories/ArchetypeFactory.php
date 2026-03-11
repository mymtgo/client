<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Archetype>
 */
class ArchetypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => $this->faker->words(2, true),
            'format' => $this->faker->randomElement(['modern', 'pioneer', 'legacy', 'standard', 'pauper']),
            'color_identity' => $this->faker->randomElement(['W', 'U', 'B', 'R', 'G', 'WU', 'BR', 'RG', null]),
        ];
    }

    public function withDecklist(): static
    {
        return $this->state(fn () => [
            'decklist_downloaded_at' => now(),
        ]);
    }

    public function staleDecklist(): static
    {
        return $this->state(fn () => [
            'decklist_downloaded_at' => now()->subDays(8),
        ]);
    }
}
