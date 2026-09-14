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

    /** Every constructed league costs the same to enter, whatever the format. */
    private const ENTRY_COST = 10.0;

    /**
     * What entering one league costs, or null for a format the table does
     * not price. Callers need this to show money in and money out; the
     * figures from `netTix` are already net of it.
     */
    public static function entryCost(string $format): ?float
    {
        return in_array(strtolower($format), self::SUPPORTED_FORMATS, true)
            ? self::ENTRY_COST
            : null;
    }

    /**
     * How many wins a run needs on average before it stops costing money.
     *
     * No whole finish lands on zero, so the answer sits between the last
     * losing record and the first paying one and is interpolated across the
     * gap. Null for a format the table does not price.
     */
    public static function breakEvenWins(string $format, int $rounds): ?float
    {
        for ($wins = 0; $wins <= $rounds; $wins++) {
            $net = self::netTix($format, $wins, $rounds - $wins);

            if ($net === null) {
                return null;
            }

            if ($net <= 0) {
                continue;
            }

            if ($wins === 0) {
                return 0.0;
            }

            $below = self::netTix($format, $wins - 1, $rounds - $wins + 1) ?? 0.0;

            return round($wins - 1 + abs($below) / (abs($below) + $net), 1);
        }

        return null;
    }

    /**
     * Net tix for a run, reading its state as well as its record.
     *
     * A dropped run is paid as though every round left unplayed was a loss,
     * because that is what dropping forfeits. A run still in progress has no
     * figure at all, and neither does a completed run holding no matches,
     * which is an empty manual entry rather than an 0-0 finish.
     */
    public static function netTixForRun(string $format, string $state, int $wins, int $losses): ?float
    {
        if ($state === 'dropped') {
            return self::netTix($format, $wins, max($losses, 5 - $wins));
        }

        if ($state !== 'complete' || $wins + $losses === 0) {
            return null;
        }

        return self::netTix($format, $wins, $losses);
    }

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
