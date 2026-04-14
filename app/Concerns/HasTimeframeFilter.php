<?php

namespace App\Concerns;

use Carbon\Carbon;

trait HasTimeframeFilter
{
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function getTimeRange(string $timeframe): array
    {
        $end = now()->endOfDay();

        $start = match ($timeframe) {
            'week' => now()->subDays(7)->startOfDay(),
            'biweekly' => now()->subWeeks(2)->startOfDay(),
            'monthly' => now()->subDays(30)->startOfDay(),
            'year' => now()->startOfYear()->startOfDay(),
            default => now()->startOfCentury()->startOfDay(),
        };

        return [$start, $end];
    }
}
