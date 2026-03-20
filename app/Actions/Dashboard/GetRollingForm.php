<?php

namespace App\Actions\Dashboard;

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

        $accountScope = fn ($q) => $q
            ->when($accountId, fn ($q2, $id) => $q2
                ->whereHas('deckVersion', fn ($q3) => $q3->whereHas('deck', fn ($q4) => $q4->where('account_id', $id)))
            );

        $recent = MtgoMatch::complete()
            ->where(fn ($q) => $accountScope($q))
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
        $rollingWinrate = $decisive > 0 ? (int) round(100 * $wins / $decisive) : 0;

        $allTime = MtgoMatch::complete()
            ->where(fn ($q) => $accountScope($q))
            ->selectRaw("
                SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) as losses
            ")
            ->first();

        $allWins = (int) $allTime->wins;
        $allTotal = $allWins + (int) $allTime->losses;
        $allTimeWinrate = $allTotal > 0 ? (int) round(100 * $allWins / $allTotal) : 0;

        return [
            'results' => $results,
            'winrate' => $rollingWinrate,
            'allTimeWinrate' => $allTimeWinrate,
            'delta' => $rollingWinrate - $allTimeWinrate,
        ];
    }
}
