<?php

namespace Database\Factories;

use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeckArchetypeNote> */
class DeckArchetypeNoteFactory extends Factory
{
    protected $model = DeckArchetypeNote::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'deck_id' => Deck::factory(),
            'archetype_id' => Archetype::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
