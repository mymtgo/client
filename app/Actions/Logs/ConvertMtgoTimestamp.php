<?php

namespace App\Actions\Logs;

use Carbon\Carbon;
use Native\Desktop\Facades\Settings;

class ConvertMtgoTimestamp
{
    /**
     * Convert an MTGO local time (HH:MM:SS) to a UTC Carbon instance.
     *
     * MTGO logs only contain a time component in the user's system clock timezone.
     * The date comes from the log file's mtime (already UTC via Carbon::createFromTimestamp).
     * We convert the UTC date to the local date, combine with the local time, then convert back to UTC.
     */
    public static function run(Carbon $loggedAt, string $mtgoTime): Carbon
    {
        $systemTz = Settings::get('system_tz', 'UTC');
        $localDate = $loggedAt->copy()->setTimezone($systemTz)->format('Y-m-d');

        return Carbon::parse($localDate.' '.$mtgoTime, $systemTz)->utc();
    }
}
