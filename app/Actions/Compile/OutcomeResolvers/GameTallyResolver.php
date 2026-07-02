<?php

namespace App\Actions\Compile\OutcomeResolvers;

use App\Data\ProjectedMatch\GameData;
use App\Data\ProjectedMatch\MatchData;
use App\Enums\MatchOutcome;
use Illuminate\Support\Collection;

/**
 * Count the projected games' won flags. Confident only when a Bo3/Bo5 win
 * threshold is met — partial tallies defer to the later resolvers.
 */
final class GameTallyResolver implements OutcomeResolver
{
    public function attempt(MatchData $match, ?array $extracted, Collection $stateChanges): ?MatchOutcome
    {
        [$wins, $losses] = self::tallyGames($match);

        $threshold = ($wins >= 3 || $losses >= 3) ? 3 : 2;

        if ($wins < $threshold && $losses < $threshold) {
            return null;
        }

        return $wins > $losses ? MatchOutcome::Win : MatchOutcome::Loss;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function tallyGames(MatchData $match): array
    {
        $wins = collect($match->games)->filter(fn (GameData $g) => $g->won === true)->count();
        $losses = collect($match->games)->filter(fn (GameData $g) => $g->won === false)->count();

        return [$wins, $losses];
    }
}
