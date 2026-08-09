<?php

namespace App\Actions\Logs;

use App\Facades\AppSettings;
use Carbon\Carbon;

class ConvertMtgoTimestamp
{
    /**
     * How far a log line may sit ahead of the file's recorded mtime before we
     * treat the date as having rolled over.
     *
     * A real rollover always lands close to a full 24 hours out — the line was
     * written just before local midnight and read just after — so the threshold
     * only has to be clear of the innocent causes of forward drift: lines
     * appended between our stat() and our read, the ambiguous hour of a DST
     * fall-back, and a stored system timezone that no longer matches the
     * machine's. Half a day clears all of them.
     */
    private const ROLLOVER_THRESHOLD_HOURS = 12;

    /**
     * Convert an MTGO local time (HH:MM:SS) to a UTC Carbon instance.
     *
     * MTGO logs only contain a time component in the user's system clock timezone.
     * The date comes from the log file's mtime (already UTC via Carbon::createFromTimestamp).
     * We convert the UTC date to the local date, combine with the local time, then convert back to UTC.
     */
    public static function run(Carbon $loggedAt, string $mtgoTime): Carbon
    {
        $systemTz = AppSettings::systemTimezone();
        $local = $loggedAt->copy()->setTimezone($systemTz);

        $converted = Carbon::parse($local->format('Y-m-d').' '.$mtgoTime, $systemTz);

        /**
         * The mtime is read when we ingest, not when the line was written, so a
         * line logged at 23:59:58 and picked up seconds later — past local
         * midnight — would otherwise be dated to the following day. That is how
         * a seven-minute match ends up recorded as lasting 1447 minutes. MTGO
         * cannot log a time meaningfully ahead of the file it just wrote to, so
         * treat a large forward jump as a date that has rolled over.
         */
        if ($converted->greaterThan($local->copy()->addHours(self::ROLLOVER_THRESHOLD_HOURS))) {
            $converted->subDay();
        }

        return $converted->utc();
    }
}
