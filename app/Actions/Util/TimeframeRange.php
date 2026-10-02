<?php

namespace App\Actions\Util;

use App\Facades\AppSettings;
use Carbon\Carbon;

class TimeframeRange
{
    /**
     * Resolve a timeframe key to UTC query bounds. Besides the preset keys, a
     * custom range is accepted as `YYYY-MM-DD..YYYY-MM-DD` (both days
     * inclusive); a malformed one falls back to all time.
     *
     * Day boundaries are taken in the user's system timezone so a timeframe
     * covers whole local days, then converted back to UTC to match how
     * match timestamps are stored.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function run(string $timeframe): array
    {
        $custom = self::customDays($timeframe);

        if ($custom !== null) {
            return [$custom[0]->utc(), $custom[1]->endOfDay()->utc()];
        }

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

        $custom = self::customDays($timeframe);

        if ($custom !== null) {
            $days = (int) $custom[0]->diffInDays($custom[1]);

            return [$end->copy()->subDays($days)->startOfDay()->utc(), $end->utc()];
        }

        $start = match ($timeframe) {
            'week' => $end->copy()->subDays(7)->startOfDay(),
            'biweekly' => $end->copy()->subWeeks(2)->startOfDay(),
            'monthly' => $end->copy()->subDays(30)->startOfDay(),
            'year' => $end->copy()->startOfYear()->startOfDay(),
            default => $end->copy()->startOfCentury()->startOfDay(),
        };

        return [$start->utc(), $end->utc()];
    }

    /**
     * The first and last day of a custom `YYYY-MM-DD..YYYY-MM-DD` range, at
     * local midnight, in order. Null for a preset key or anything malformed:
     * the value arrives in a query string, so it is not trusted.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private static function customDays(string $timeframe): ?array
    {
        $parts = explode('..', $timeframe);

        if (count($parts) !== 2) {
            return null;
        }

        $days = [];

        foreach ($parts as $part) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $part) !== 1) {
                return null;
            }

            $day = Carbon::createFromFormat('!Y-m-d', $part, AppSettings::systemTimezone());

            // createFromFormat rolls 2026-02-31 over into March; reject it.
            if (! $day || $day->format('Y-m-d') !== $part) {
                return null;
            }

            $days[] = $day;
        }

        usort($days, fn (Carbon $a, Carbon $b) => $a <=> $b);

        return [$days[0], $days[1]];
    }
}
