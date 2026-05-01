<?php

namespace App\Support\Leagues;

class LeagueEvTable
{
    /**
     * Constructed league EV table. All 5-round leagues share the same 10-tix entry
     * prize structure regardless of format.
     *
     * @var array<string, float>
     */
    private const SCORE_EV = [
        '5-0' => 29.02,
        '4-1' => 12.70,
        '3-2' => 2.62,
        '2-3' => -5.00,
        '1-4' => -10.00,
        '0-5' => -10.00,
    ];

    private const SUPPORTED_FORMATS = ['modern', 'pioneer', 'legacy', 'standard', 'vintage', 'pauper'];

    public static function netTix(string $format, int $wins, int $losses): ?float
    {
        $key = strtolower($format);

        if (! in_array($key, self::SUPPORTED_FORMATS, true)) {
            return null;
        }

        $score = $wins.'-'.$losses;

        return self::SCORE_EV[$score] ?? null;
    }
}
