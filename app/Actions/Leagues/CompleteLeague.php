<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueState;
use App\Enums\MatchState;
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

    /**
     * Complete the league only if it has played a full run: three matches for
     * a draft league, five for constructed and sealed. Every path that can
     * leave a finished run looking live goes through here, so the rule lives
     * in one place.
     */
    public static function runIfFinished(League $league): void
    {
        $played = $league->matches()->where('state', MatchState::Complete)->count();

        if ($played >= $league->kind->roundCount()) {
            self::run($league);
        }
    }
}
