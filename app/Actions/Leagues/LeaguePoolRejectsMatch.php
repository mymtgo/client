<?php

namespace App\Actions\Leagues;

use App\Actions\Limited\ReadRegisteredDeck;
use App\Enums\DraftState;
use App\Models\League;
use App\Models\MtgoMatch;

class LeaguePoolRejectsMatch
{
    /**
     * True when this match's registered deck cannot have been built from the
     * league's pool, so the match belongs to a different run.
     *
     * Only limited leagues have a pool, and only a Finished draft has all of
     * it: a catch-up replay can still be projecting picks, and comparing a
     * registered deck against a half-built pool reads as low coverage and
     * would wrongly split the league. Anything it cannot tell is not a
     * rejection.
     */
    public static function run(League $league, MtgoMatch $match): bool
    {
        if (! $league->kind->isLimited() || ! $match->token) {
            return false;
        }

        $draft = $league->draft;

        if (! $draft || $draft->state !== DraftState::Finished) {
            return false;
        }

        $cards = ReadRegisteredDeck::run($match);

        if ($cards === null) {
            return false;
        }

        return ! DeckFitsLeaguePool::run($league, ReadRegisteredDeck::mainDeck($cards));
    }
}
