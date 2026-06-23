<?php

namespace Database\Factories;

use App\Models\Opponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opponent>
 */
class OpponentFactory extends Factory
{
    protected $model = Opponent::class;

    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
        ];
    }
}
