<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LogEvent>
 */
class LogEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'file_path' => '/tmp/test-log.dat',
            'byte_offset_start' => fake()->numberBetween(0, 10000),
            'byte_offset_end' => fake()->numberBetween(10001, 20000),
            'timestamp' => fake()->time('H:i:s'),
            'level' => 'INF',
            'category' => 'Match',
            'context' => 'TestContext',
            'raw_text' => fake()->sentence(),
            'ingested_at' => now(),
            'event_type' => null,
            'logged_at' => now(),
            'match_id' => null,
            'match_token' => null,
            'game_id' => null,
            'username' => null,
            'processed_at' => null,
        ];
    }
}
