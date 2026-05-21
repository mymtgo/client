<?php

namespace Database\Factories;

use App\Models\LogEvent;
use App\Models\LogInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogEvent>
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
            'log_instance_id' => LogInstance::factory(),
            'file_path' => '/tmp/fake.log',
            'byte_offset_start' => fake()->unique()->numberBetween(0, 9_999_999),
            'byte_offset_end' => fake()->numberBetween(100, 200),
            'timestamp' => '12:00:00',
            'level' => 'INF',
            'category' => 'Test',
            'context' => '',
            'raw_text' => fake()->sentence(),
            'ingested_at' => now(),
            'logged_at' => now(),
            'event_type' => null,
            'tournament_token' => null,
            'match_token' => null,
        ];
    }
}
