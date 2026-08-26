<?php

namespace Database\Factories;

use App\Models\Draft;
use App\Models\DraftPick;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DraftPick> */
class DraftPickFactory extends Factory
{
    /**
     * Process-wide pick sequence. Faker's unique() pool is exhausted after 42
     * picks and then throws, so the default ordinal walks a plain counter and
     * tests that build more than one full draft keep working.
     */
    protected static int $nextOrdinal = 1;

    /**
     * Rewind the sequence so each test starts from pick 1 and the ordinals a
     * test sees do not depend on the tests that ran before it.
     */
    public static function resetOrdinals(): void
    {
        self::$nextOrdinal = 1;
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'draft_id' => Draft::factory(),
            'ordinal' => fn () => self::$nextOrdinal++,
            'pack_number' => fn (array $attributes) => intdiv(((int) $attributes['ordinal'] - 1) % 42, 14) + 1,
            'pick_number' => fn (array $attributes) => (((int) $attributes['ordinal'] - 1) % 14) + 1,
            'pack_id' => fake()->numberBetween(143_000_000, 144_000_000),
            'direction' => 0,
            'cards_available' => [fake()->numberBetween(150_000, 155_000)],
            'reservations' => [],
            'shown_at' => now(),
        ];
    }
}
