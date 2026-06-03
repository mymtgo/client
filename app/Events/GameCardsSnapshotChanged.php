<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Events\Dispatchable;

class GameCardsSnapshotChanged
{
    use Dispatchable;

    public function __construct(public int $matchId) {}

    /**
     * @return array<int, Channel|string>
     */
    public function broadcastOn(): array
    {
        return ['nativephp'];
    }
}
