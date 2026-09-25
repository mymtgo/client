<?php

namespace App\Actions\Sidecar;

use App\Sidecar\SidecarAuthorityFlags;
use Carbon\CarbonInterface;

class AwaitSidecarAnswer
{
    public const WINDOW_SECONDS = 5;

    public const LIVE_SECONDS = 120;

    /**
     * Whether a seam should leave its field unset for now so the sidecar's
     * snapshot can decide it (spec 6.2). The retry paths pick the field up
     * on a later tick; after the window the log path decides as today.
     *
     * The anchor is an app-clock created_at, never an MTGO timestamp. The
     * live check stops a startup backlog from waiting: the sidecar was
     * closed with the app and has nothing for those matches, and waiting
     * would push them into the newest-first retry pass out of order.
     *
     * Settings and a file stat only: no queries, so sidecar-less machines
     * pay nothing.
     */
    public static function run(string $field, ?CarbonInterface $anchor, ?CarbonInterface $liveAt = null): bool
    {
        if ($anchor === null || ! SidecarAuthorityFlags::isOn($field)) {
            return false;
        }

        if ($anchor->lt(now()->subSeconds(self::WINDOW_SECONDS))) {
            return false;
        }

        if ($liveAt !== null && abs(now()->diffInSeconds($liveAt)) > self::LIVE_SECONDS) {
            return false;
        }

        $status = ReadSidecarStatus::run();

        return $status !== null && $status->state === 'attached' && ! $status->isStale();
    }
}
