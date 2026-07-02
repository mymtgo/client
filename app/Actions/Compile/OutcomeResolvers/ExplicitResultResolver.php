<?php

namespace App\Actions\Compile\OutcomeResolvers;

use App\Data\ProjectedMatch\MatchData;
use App\Enums\MatchOutcome;
use Illuminate\Support\Collection;

/**
 * MTGO's own "wins the match X-Y" score line — the most authoritative
 * signal. The score is [localWins, opponentWins], extracted from the
 * decoded MetaMessage stream by ExtractGameResults.
 */
final class ExplicitResultResolver implements OutcomeResolver
{
    public function attempt(MatchData $match, ?array $extracted, Collection $stateChanges): ?MatchOutcome
    {
        if ($extracted === null || ! ($extracted['match_decided'] ?? false)) {
            return null;
        }

        $score = $extracted['match_score'] ?? null;

        if ($score === null) {
            return null;
        }

        return match (true) {
            $score[0] > $score[1] => MatchOutcome::Win,
            $score[0] < $score[1] => MatchOutcome::Loss,
            default => MatchOutcome::Draw,
        };
    }
}
