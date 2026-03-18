<?php

namespace Database\Factories;

use App\Models\Deck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeckVersion>
 */
class DeckVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'deck_id' => Deck::factory(),
            'signature' => base64_encode(fake()->uuid().':4:false|'.fake()->uuid().':4:false'),
            'modified_at' => now(),
        ];
    }
}
