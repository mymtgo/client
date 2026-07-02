<?php

namespace App\Actions\Compile\OutcomeResolvers;

use App\Data\ProjectedMatch\MatchData;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use Illuminate\Support\Collection;

/**
 * Last resort for matches the server closed below the win threshold —
 * opponent drops, forfeits during sideboarding, trailing disconnects. The
 * projection only reaches Complete on a terminal server signal, so a
 * leaning partial tally is trustworthy here (mirrors the 0.x
 * ResolveMatchFromMetaMessages "trust the completed signal" rule).
 *
 * A live disconnect is deliberately NOT resolved — the player may
 * reconnect, and the match is still InProgress, so this resolver defers.
 */
final class ServerClosedTallyResolver implements OutcomeResolver
{
    public function attempt(MatchData $match, ?array $extracted, Collection $stateChanges): ?MatchOutcome
    {
        if ($match->state !== MatchState::Complete->value) {
            return null;
        }

        [$wins, $losses] = GameTallyResolver::tallyGames($match);

        return match (true) {
            $wins > $losses => MatchOutcome::Win,
            $losses > $wins => MatchOutcome::Loss,
            $wins > 0 => MatchOutcome::Draw,
            default => null,
        };
    }
}
