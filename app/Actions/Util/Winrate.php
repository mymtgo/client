<?php

namespace App\Actions\Util;

class Winrate
{
    /**
     * Calculate winrate as an integer percentage (0-100).
     *
     * Draws count as matches played: the percentage always matches the
     * W-L-D record shown beside it, and players who prefer to ignore draws
     * can see the number to adjust for. Game-level callers pass no draws.
     */
    public static function percentage(int|float $wins, int|float $losses, int|float $draws = 0): int
    {
        $total = $wins + $losses + $draws;

        return $total > 0 ? (int) round(100 * $wins / $total) : 0;
    }
}
