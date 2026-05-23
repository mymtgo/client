<?php

namespace Database\Factories;

use App\Models\LogInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogInstance>
 */
class LogInstanceFactory extends Factory
{
    protected $model = LogInstance::class;

    public function definition(): array
    {
        $headHash = sha1((string) fake()->unique()->uuid());

        return [
            'file_path' => '/fake/mtgo.log',
            'identity_hash' => sha1($headHash.':'.fake()->unixTime()),
            'file_ctime' => fake()->unixTime(),
            'head_hash' => $headHash,
            'anchor_offset' => null,
            'anchor_hash' => null,
            'tail_hash' => null,
            'local_username' => null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'sealed_at' => null,
            'seal_reason' => null,
        ];
    }

    public function sealed(string $reason = 'manual'): self
    {
        return $this->state(fn () => [
            'sealed_at' => now(),
            'seal_reason' => $reason,
        ]);
    }
}
