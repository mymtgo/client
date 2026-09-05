<?php

namespace Database\Factories;

use App\Enums\SideboardDirection;
use App\Models\SideboardGuide;
use App\Models\SideboardGuideCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SideboardGuideCard> */
class SideboardGuideCardFactory extends Factory
{
    protected $model = SideboardGuideCard::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sideboard_guide_id' => SideboardGuide::factory(),
            'oracle_id' => 'o-'.fake()->unique()->lexify('????'),
            'direction' => SideboardDirection::In,
            'quantity' => fake()->numberBetween(1, 4),
        ];
    }

    public function out(): static
    {
        return $this->state(fn () => ['direction' => SideboardDirection::Out]);
    }
}
