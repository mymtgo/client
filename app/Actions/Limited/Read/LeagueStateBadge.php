<?php

namespace App\Actions\Limited\Read;

use App\Enums\DraftState;
use App\Enums\LeagueState;
use App\Models\Draft;
use App\Models\League;

class LeagueStateBadge
{
    /**
     * The display label and badge variant for a limited league. An abandoned
     * draft outranks the league state: the league may still look active while
     * the draft it depends on never finished.
     *
     * @return array{0: string, 1: string}
     */
    public static function run(League $league, ?Draft $draft, int $matchCount): array
    {
        if ($draft?->state === DraftState::Abandoned) {
            return ['Draft abandoned', 'warning'];
        }

        return match ($league->state) {
            LeagueState::Active => ['Active', 'default'],
            LeagueState::Complete => $matchCount === 0 ? ['No matches', 'warning'] : ['Complete', 'success'],
            LeagueState::Partial, LeagueState::Dropped => ['Dropped', 'destructive'],
        };
    }
}
