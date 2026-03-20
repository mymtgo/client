<?php

namespace App\Actions\Dashboard;

use App\Enums\MatchOutcome;
use App\Models\MtgoMatch;
use Carbon\Carbon;

class GetStreak
{
    /**
     * @return array{current: string|null, bestWin: int, bestLoss: int}
     */
    public static function run(?int $accountId, Carbon $from, Carbon $to): array
    {
        $accountScope = fn ($q) => $q
            ->when($accountId, fn ($q2, $id) => $q2
                ->whereHas('deckVersion', fn ($q3) => $q3->whereHas('deck', fn ($q4) => $q4->where('account_id', $id)))
            );

        $recentMatches = MtgoMatch::complete()
            ->where(fn ($q) => $accountScope($q))
            ->whereBetween('started_at', [$from, $to])
            ->orderByDesc('started_at')
            ->pluck('outcome');

        $current = self::computeCurrentStreak($recentMatches);

        $allMatches = MtgoMatch::complete()
            ->where(fn ($q) => $accountScope($q))
            ->orderBy('started_at')
            ->pluck('outcome');

        [$bestWin, $bestLoss] = self::computeBestStreaks($allMatches);

        return [
            'current' => $current,
            'bestWin' => $bestWin,
            'bestLoss' => $bestLoss,
        ];
    }

    private static function computeCurrentStreak($outcomes): ?string
    {
        if ($outcomes->isEmpty()) {
            return null;
        }

        $first = $outcomes->first();
        if (! in_array($first, [MatchOutcome::Win, MatchOutcome::Loss])) {
            return null;
        }

        $count = 0;
        foreach ($outcomes as $outcome) {
            if ($outcome !== $first) {
                break;
            }
            $count++;
        }

        $label = $first === MatchOutcome::Win ? 'W' : 'L';

        return "{$count}{$label}";
    }

    private static function computeBestStreaks($outcomes): array
    {
        $bestWin = 0;
        $bestLoss = 0;
        $currentWin = 0;
        $currentLoss = 0;

        foreach ($outcomes as $outcome) {
            if ($outcome === MatchOutcome::Win) {
                $currentWin++;
                $currentLoss = 0;
                $bestWin = max($bestWin, $currentWin);
            } elseif ($outcome === MatchOutcome::Loss) {
                $currentLoss++;
                $currentWin = 0;
                $bestLoss = max($bestLoss, $currentLoss);
            } else {
                $currentWin = 0;
                $currentLoss = 0;
            }
        }

        return [$bestWin, $bestLoss];
    }
}
