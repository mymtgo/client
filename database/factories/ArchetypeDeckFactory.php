<?php

namespace Database\Factories;

use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ArchetypeDeck> */
class ArchetypeDeckFactory extends Factory
{
    protected $model = ArchetypeDeck::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'archetype_id' => Archetype::factory(),
            'seen_count' => $this->faker->numberBetween(1, 50),
            'last_synced_at' => now(),
        ];
    }
}
