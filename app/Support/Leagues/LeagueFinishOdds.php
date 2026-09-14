<?php

namespace App\Support\Leagues;

class LeagueFinishOdds
{
    /**
     * Where a league run in progress is likely to end up.
     *
     * A league is always played to its full round count, so the remaining
     * rounds are a plain binomial on the given per-round win probability.
     * There is no early exit to model: the prize table carries 0-5 as a real
     * finish, which it could not if three losses ended the run.
     *
     * The prize threshold is read out of the table rather than hardcoded, so
     * "wins for prize" means the first finish that actually pays rather than
     * a number someone typed. Formats the table does not cover return nulls
     * throughout, which is the caller's signal to hide the figures.
     *
     * @return array{roundsLeft: int, prizeWins: int|null, winsForPrize: int|null, chanceOfPrize: int|null, expectedTix: float|null}
     */
    public static function run(string $format, int $wins, int $losses, int $rounds, float $winProbability): array
    {
        $roundsLeft = max(0, $rounds - $wins - $losses);
        $prizeWins = self::prizeThreshold($format, $rounds);

        if ($prizeWins === null) {
            return [
                'roundsLeft' => $roundsLeft,
                'prizeWins' => null,
                'winsForPrize' => null,
                'chanceOfPrize' => null,
                'expectedTix' => null,
            ];
        }

        $chanceOfPrize = 0.0;
        $expectedTix = 0.0;

        foreach (self::outcomeProbabilities($roundsLeft, $winProbability) as $extraWins => $probability) {
            $finalWins = $wins + $extraWins;
            $finalLosses = $losses + $roundsLeft - $extraWins;

            if ($finalWins >= $prizeWins) {
                $chanceOfPrize += $probability;
            }

            $expectedTix += $probability * (LeagueEvTable::netTix($format, $finalWins, $finalLosses) ?? 0.0);
        }

        return [
            'roundsLeft' => $roundsLeft,
            'prizeWins' => $prizeWins,
            'winsForPrize' => max(0, $prizeWins - $wins),
            'chanceOfPrize' => (int) round($chanceOfPrize * 100),
            'expectedTix' => round($expectedTix, 2),
        ];
    }

    /**
     * The fewest wins that leave the run in profit, or null when the prize
     * table has nothing to say about this format.
     */
    private static function prizeThreshold(string $format, int $rounds): ?int
    {
        for ($wins = 0; $wins <= $rounds; $wins++) {
            $net = LeagueEvTable::netTix($format, $wins, $rounds - $wins);

            if ($net === null) {
                return null;
            }

            if ($net > 0) {
                return $wins;
            }
        }

        return null;
    }

    /**
     * Probability of each possible win count across the remaining rounds.
     *
     * @return array<int, float>
     */
    private static function outcomeProbabilities(int $roundsLeft, float $winProbability): array
    {
        $probabilities = [];

        for ($wins = 0; $wins <= $roundsLeft; $wins++) {
            $probabilities[$wins] = self::binomialCoefficient($roundsLeft, $wins)
                * $winProbability ** $wins
                * (1 - $winProbability) ** ($roundsLeft - $wins);
        }

        return $probabilities;
    }

    private static function binomialCoefficient(int $n, int $k): float
    {
        $result = 1.0;

        for ($i = 1; $i <= $k; $i++) {
            $result = $result * ($n - $k + $i) / $i;
        }

        return $result;
    }
}
