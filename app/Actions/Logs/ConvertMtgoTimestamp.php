<?php

namespace App\Actions\Logs;

use App\Models\AppSetting;
use Carbon\Carbon;

class ConvertMtgoTimestamp
{
    /**
     * Convert an MTGO local time (HH:MM:SS) to a UTC Carbon instance.
     *
     * MTGO logs only contain a time component in the user's system clock timezone.
     * The date comes from the log file's mtime (already UTC via Carbon::createFromTimestamp).
     * We convert the UTC date to the local date, combine with the local time, then convert back to UTC.
     *
     * Uses the stored display timezone from AppSetting rather than System::timezone()
     * because the NativePHP IPC call can return null during job processing,
     * which would cause local times to be stored as UTC without conversion.
     */
    public static function run(Carbon $loggedAt, string $mtgoTime): Carbon
    {
        $systemTz = AppSetting::displayTimezone();
        $localDate = $loggedAt->copy()->setTimezone($systemTz)->format('Y-m-d');

        return Carbon::parse($localDate.' '.$mtgoTime, $systemTz)->utc();
    }

    /**
     * Convert a decoded_entries timestamp from local-as-UTC to real UTC.
     *
     * ParseGameLogBinary stores .NET DateTime ticks (local wall-clock time)
     * with a +00:00 UTC label. This method strips the false UTC label,
     * re-interprets the wall-clock time in the user's timezone, and converts to real UTC.
     */
    public static function fromDecodedEntry(string $timestamp, ?string $userTz = null): Carbon
    {
        $userTz ??= AppSetting::displayTimezone();
        $wallClock = Carbon::parse($timestamp)->format('Y-m-d H:i:s');

        return Carbon::parse($wallClock, $userTz)->utc();
    }
}
