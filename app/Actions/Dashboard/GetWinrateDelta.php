<?php

namespace App\Actions\Dashboard;

use App\Actions\Util\TimeframeRange;
use App\Actions\Util\Winrate;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Support\MatchRecord;
use Carbon\Carbon;

class GetWinrateDelta
{
    /**
     * @return array{matchDelta: int, gameDelta: int}
     */
    public static function run(?int $accountId, Carbon $currentStart, Carbon $currentEnd, string $timeframe, ?string $format = null): array
    {
        return once(function () use ($accountId, $currentStart, $currentEnd, $timeframe, $format) {
            if (! $accountId) {
                return ['matchDelta' => 0, 'gameDelta' => 0];
            }

            [$previousStart, $previousEnd] = TimeframeRange::previous($timeframe, $currentStart);

            $currentMatchWinrate = self::matchWinrate($accountId, $currentStart, $currentEnd, $format);
            $previousMatchWinrate = self::matchWinrate($accountId, $previousStart, $previousEnd, $format);

            $currentGameWinrate = self::gameWinrate($accountId, $currentStart, $currentEnd, $format);
            $previousGameWinrate = self::gameWinrate($accountId, $previousStart, $previousEnd, $format);

            return [
                'matchDelta' => $currentMatchWinrate - $previousMatchWinrate,
                'gameDelta' => $currentGameWinrate - $previousGameWinrate,
            ];
        });
    }

    private static function matchWinrate(int $accountId, Carbon $from, Carbon $to, ?string $format = null): int
    {
        return MatchRecord::fromQuery(
            MtgoMatch::complete()
                ->forAccount($accountId)
                ->when($format, fn ($q, $f) => $q->where('format', $f))
                ->whereBetween('started_at', [$from, $to]),
        )->winrate();
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
     * The previous window ends one second before the current one starts, with
     * its day boundaries taken in the system timezone and returned as UTC.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
}
