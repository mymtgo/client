<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueState;
use App\Models\League;

class CompleteLeague
{
    /**
     * Mark a league as Complete and stamp the completion timestamp.
     * Single source of truth for the Active → Complete transition.
     * Idempotent: re-running on an already Complete league is a no-op.
     */
    public static function run(League $league): void
    {
        if ($league->state === LeagueState::Complete) {
            return;
        }

        $league->update([
            'state' => LeagueState::Complete,
            'completed_at' => now(),
        ]);
    }
}
