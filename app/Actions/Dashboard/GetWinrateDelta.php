<?php

namespace App\Actions\Dashboard;

use App\Actions\Util\Winrate;
use App\Models\Game;
use App\Models\MtgoMatch;
use Carbon\Carbon;

class GetWinrateDelta
{
    /**
     * @return array{matchDelta: int, gameDelta: int}
     */
    public static function run(?int $accountId, Carbon $currentStart, Carbon $currentEnd, string $timeframe, ?string $format = null): array
    {
        if (! $accountId) {
            return ['matchDelta' => 0, 'gameDelta' => 0];
        }

        [$previousStart, $previousEnd] = self::getPreviousTimeRange($timeframe, $currentStart);

        $currentMatchWinrate = self::matchWinrate($accountId, $currentStart, $currentEnd, $format);
        $previousMatchWinrate = self::matchWinrate($accountId, $previousStart, $previousEnd, $format);

        $currentGameWinrate = self::gameWinrate($accountId, $currentStart, $currentEnd, $format);
        $previousGameWinrate = self::gameWinrate($accountId, $previousStart, $previousEnd, $format);

        return [
            'matchDelta' => $currentMatchWinrate - $previousMatchWinrate,
            'gameDelta' => $currentGameWinrate - $previousGameWinrate,
        ];
    }

    private static function matchWinrate(int $accountId, Carbon $from, Carbon $to, ?string $format = null): int
    {
        $query = MtgoMatch::complete()
            ->forAccount($accountId)
            ->when($format, fn ($q, $f) => $q->where('format', $f))
            ->whereBetween('started_at', [$from, $to]);

        $stats = $query->selectRaw("
            SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) as losses
        ")->first();

        return Winrate::percentage((int) $stats->wins, (int) $stats->losses);
    }

    private static function gameWinrate(int $accountId, Carbon $from, Carbon $to, ?string $format = null): int
    {
        $matchIds = MtgoMatch::complete()
            ->forAccount($accountId)
            ->when($format, fn ($q, $f) => $q->where('format', $f))
            ->whereBetween('started_at', [$from, $to])
            ->pluck('matches.id');

        $won = Game::whereIn('match_id', $matchIds)->where('won', true)->count();
        $lost = Game::whereIn('match_id', $matchIds)->where('won', false)->count();

        return Winrate::percentage($won, $lost);
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
