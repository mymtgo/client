<?php

namespace App\Actions\Dashboard;

use App\Models\Game;
use App\Models\MtgoMatch;
use Carbon\Carbon;

class GetWinrateDelta
{
    /**
     * @return array{matchDelta: int, gameDelta: int}
     */
    public static function run(?int $accountId, Carbon $currentStart, Carbon $currentEnd, string $timeframe): array
    {
        [$previousStart, $previousEnd] = self::getPreviousTimeRange($timeframe, $currentStart);

        $currentMatchWinrate = self::matchWinrate($accountId, $currentStart, $currentEnd);
        $previousMatchWinrate = self::matchWinrate($accountId, $previousStart, $previousEnd);

        $currentGameWinrate = self::gameWinrate($accountId, $currentStart, $currentEnd);
        $previousGameWinrate = self::gameWinrate($accountId, $previousStart, $previousEnd);

        return [
            'matchDelta' => $currentMatchWinrate - $previousMatchWinrate,
            'gameDelta' => $currentGameWinrate - $previousGameWinrate,
        ];
    }

    private static function matchWinrate(?int $accountId, Carbon $from, Carbon $to): int
    {
        $query = MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id))))
            ->whereBetween('started_at', [$from, $to]);

        $stats = $query->selectRaw("
            SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) as losses
        ")->first();

        $wins = (int) $stats->wins;
        $total = $wins + (int) $stats->losses;

        return $total > 0 ? (int) round(100 * $wins / $total) : 0;
    }

    private static function gameWinrate(?int $accountId, Carbon $from, Carbon $to): int
    {
        $matchIds = MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id))))
            ->whereBetween('started_at', [$from, $to])
            ->pluck('matches.id');

        $won = Game::whereIn('match_id', $matchIds)->where('won', true)->count();
        $lost = Game::whereIn('match_id', $matchIds)->where('won', false)->count();
        $total = $won + $lost;

        return $total > 0 ? (int) round(100 * $won / $total) : 0;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function getPreviousTimeRange(string $timeframe, Carbon $currentStart): array
    {
        $end = $currentStart->copy()->subSecond();

        $start = match ($timeframe) {
            'biweekly' => $end->copy()->subWeeks(2)->startOfDay(),
            'monthly' => $end->copy()->subDays(30)->startOfDay(),
            'year' => $end->copy()->startOfYear()->startOfDay(),
            'alltime' => now()->startOfCentury()->startOfDay(),
            default => $end->copy()->subDays(7)->startOfDay(),
        };

        return [$start, $end];
    }
}
