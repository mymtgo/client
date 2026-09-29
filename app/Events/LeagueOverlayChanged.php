<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something the league overlay shows (record, game pips, active match) moved.
 *
 * Deferred until commit: NativePHP posts the event to Electron the moment it
 * is dispatched, and the overlay reloads straight away. Fired mid-transaction,
 * that reload would read the pre-commit rows and show the old record.
 */
class LeagueOverlayChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @return array<int, Channel|string>
     */
    public function broadcastOn(): array
    {
        return ['nativephp'];
    }
}
