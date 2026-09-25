<?php

namespace App\Sidecar;

use App\Enums\LeagueState;

final readonly class LeagueCandidate
{
    /**
     * @param  list<string>  $otherMatchMtgoIds  the league's local matches, excluding the match being decided
     */
    public function __construct(
        public int $id,
        public LeagueState $state,
        public array $otherMatchMtgoIds,
    ) {}

    public function hasOtherMatches(): bool
    {
        return $this->otherMatchMtgoIds !== [];
    }
}
