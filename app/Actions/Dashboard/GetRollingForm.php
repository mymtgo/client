<?php

namespace App\Actions\Dashboard;

use App\Actions\Util\Winrate;
use App\Enums\MatchOutcome;
use App\Models\MtgoMatch;

class GetRollingForm
{
    /**
     * @return array{results: string[], winrate: int, allTimeWinrate: int, delta: int}
     */
    public static function run(?int $accountId): array
    {
        $empty = ['results' => [], 'winrate' => 0, 'allTimeWinrate' => 0, 'delta' => 0];

        if (! $accountId) {
            return $empty;
        }

        $recent = MtgoMatch::complete()
            ->forAccount($accountId)
            ->orderByDesc('started_at')
            ->limit(20)
            ->pluck('outcome');

        if ($recent->isEmpty()) {
            return $empty;
        }

        $results = $recent->reverse()->values()->map(fn (MatchOutcome $o) => match ($o) {
            MatchOutcome::Win => 'W',
            MatchOutcome::Loss => 'L',
            default => 'D',
        })->all();

        $wins = $recent->filter(fn ($o) => $o === MatchOutcome::Win)->count();
        $decisive = $recent->filter(fn ($o) => in_array($o, [MatchOutcome::Win, MatchOutcome::Loss]))->count();
        $rollingWinrate = Winrate::percentage($wins, $decisive - $wins);

        $allTime = MtgoMatch::complete()
            ->forAccount($accountId)
            ->selectRaw("
                SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) as losses
            ")
            ->first();

        $allWins = (int) $allTime->wins;
        $allTotal = $allWins + (int) $allTime->losses;
        $allTimeWinrate = Winrate::percentage($allWins, (int) $allTime->losses);

        return [
            'results' => $results,
            'winrate' => $rollingWinrate,
            'allTimeWinrate' => $allTimeWinrate,
            'delta' => $rollingWinrate - $allTimeWinrate,
        ];
    }
}
