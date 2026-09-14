<?php

namespace App\Actions\Util;

use App\Facades\AppSettings;
use Carbon\Carbon;

class TimeframeRange
{
    /**
     * Resolve a timeframe key to UTC query bounds.
     *
     * Day boundaries are taken in the user's system timezone so a timeframe
     * covers whole local days, then converted back to UTC to match how
     * match timestamps are stored.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function run(string $timeframe): array
    {
        $now = now()->setTimezone(AppSettings::systemTimezone());

        $end = $now->copy()->endOfDay();

        $start = match ($timeframe) {
            'week' => $now->copy()->subDays(7)->startOfDay(),
            'biweekly' => $now->copy()->subWeeks(2)->startOfDay(),
            'monthly' => $now->copy()->subDays(30)->startOfDay(),
            'year' => $now->copy()->startOfYear()->startOfDay(),
            default => $now->copy()->startOfCentury()->startOfDay(),
        };

        return [$start->utc(), $end->utc()];
    }

    /**
     * The window immediately before the one starting at `$currentStart`.
     *
     * Used for period-over-period comparisons, so it ends one second before
     * the current window opens and spans the same length. Keyed off the same
     * timeframe list as `run()` to keep the two from drifting apart.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function previous(string $timeframe, Carbon $currentStart): array
    {
        $end = $currentStart->copy()->setTimezone(AppSettings::systemTimezone())->subSecond();

        $start = match ($timeframe) {
            'week' => $end->copy()->subDays(7)->startOfDay(),
            'biweekly' => $end->copy()->subWeeks(2)->startOfDay(),
            'monthly' => $end->copy()->subDays(30)->startOfDay(),
            'year' => $end->copy()->startOfYear()->startOfDay(),
            default => $end->copy()->startOfCentury()->startOfDay(),
        };

        return [$start->utc(), $end->utc()];
    }
}
