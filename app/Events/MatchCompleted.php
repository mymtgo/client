<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast when a match transitions to Complete so open match-list pages
 * can partial-reload. After-commit so the reload's request always sees the
 * committed row.
 */
class MatchCompleted implements ShouldDispatchAfterCommit
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
