<?php

namespace App\Actions\Compile\OutcomeResolvers;

use App\Data\ProjectedMatch\MatchData;
use App\Enums\MatchOutcome;
use App\Models\LogEvent;
use Illuminate\Support\Collection;

interface OutcomeResolver
{
    /**
     * Attempt to determine the local player's match outcome.
     *
     * @param  array{games: array<int, array<string, mixed>>, players: array<int, string>, match_score: ?array{0: int, 1: int}, match_decided: bool}|null  $extracted  ExtractGameResults output for the match (null when no MetaMessage entries exist)
     * @param  Collection<int, LogEvent>  $stateChanges  The token's match_state_changed events
     * @return MatchOutcome|null null = not confident, defer to the next resolver
     */
    public function attempt(MatchData $match, ?array $extracted, Collection $stateChanges): ?MatchOutcome;
}
