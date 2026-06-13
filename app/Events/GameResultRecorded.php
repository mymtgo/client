<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast when a game's won flag is first set or changes, so live-game
 * overlays update without waiting on their poll. After-commit: the dispatch
 * site can sit inside AdvanceMatchState's TimedTransaction.
 */
class GameResultRecorded implements ShouldDispatchAfterCommit
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
