<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Events\Dispatchable;

class ArchetypeEstimateUpdated
{
    use Dispatchable;

    public function __construct(
        public string $matchToken,
        public string $playerName,
        public string $archetypeName,
        public ?string $archetypeColorIdentity,
        public int $confidence,
        public int $cardsSeen,
    ) {}

    /**
     * @return array<int, Channel|string>
     */
    public function broadcastOn(): array
    {
        return ['nativephp'];
    }
}
