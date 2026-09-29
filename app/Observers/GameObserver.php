<?php

namespace App\Observers;

use App\Events\LeagueOverlayChanged;
use App\Models\Game;

class GameObserver
{
    /**
     * A new game adds an in-progress pip to the league overlay.
     */
    public function created(Game $game): void
    {
        self::notifyLeagueOverlay($game);
    }

    /**
     * A game result or end fills in its pip on the league overlay.
     */
    public function updated(Game $game): void
    {
        if (! $game->isDirty(['won', 'ended_at'])) {
            return;
        }

        self::notifyLeagueOverlay($game);
    }

    private static function notifyLeagueOverlay(Game $game): void
    {
        if ($game->match?->league_id === null) {
            return;
        }

        LeagueOverlayChanged::dispatch();
    }
}
