<?php

namespace App\Actions\Util;

class Winrate
{
    /**
     * Calculate winrate as an integer percentage (0-100).
     */
    public static function percentage(int|float $wins, int|float $losses): int
    {
        $total = $wins + $losses;

        return $total > 0 ? (int) round(100 * $wins / $total) : 0;
    }
}
