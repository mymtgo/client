<?php

namespace App\Actions\Dashboard;

use App\Enums\MatchOutcome;
use App\Models\MtgoMatch;
use App\Support\MatchRecord;

class GetRollingForm
{
    /**
     * @return array{results: string[], winrate: int, allTimeWinrate: int, delta: int}
     */
    public static function run(?int $accountId, ?string $format = null): array
    {
        $empty = ['results' => [], 'winrate' => 0, 'allTimeWinrate' => 0, 'delta' => 0];

        if (! $accountId) {
            return $empty;
        }

        $recent = MtgoMatch::complete()
            ->forAccount($accountId)
            ->when($format, fn ($q, $f) => $q->where('format', $f))
            ->whereNotNull('outcome')
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

        $rollingWinrate = MatchRecord::fromCounts(
            wins: $recent->filter(fn ($o) => $o === MatchOutcome::Win)->count(),
            losses: $recent->filter(fn ($o) => $o === MatchOutcome::Loss)->count(),
            draws: $recent->filter(fn ($o) => ! in_array($o, [MatchOutcome::Win, MatchOutcome::Loss]))->count(),
        )->winrate();

        $allTimeWinrate = MatchRecord::fromQuery(
            MtgoMatch::complete()
                ->forAccount($accountId)
                ->when($format, fn ($q, $f) => $q->where('format', $f)),
        )->winrate();

        return [
            'results' => $results,
            'winrate' => $rollingWinrate,
            'allTimeWinrate' => $allTimeWinrate,
            'delta' => $rollingWinrate - $allTimeWinrate,
        ];
    }
}
