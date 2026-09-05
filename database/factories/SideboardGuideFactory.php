<?php

namespace Database\Factories;

use App\Models\Archetype;
use App\Models\Deck;
use App\Models\SideboardGuide;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SideboardGuide> */
class SideboardGuideFactory extends Factory
{
    protected $model = SideboardGuide::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'deck_id' => Deck::factory(),
            'archetype_id' => Archetype::factory(),
        ];
    }
}
