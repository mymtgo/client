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
}
