<?php

namespace App\Actions\Device;

use App\Facades\AppSettings;
use Illuminate\Support\Str;

/**
 * The stable per-install device identifier sent as X-Device-Id on every
 * API request. A ULID minted once and cached in AppSettings — the API
 * rejects non-ULID device ids, and uniqueness is scoped per (user, device)
 * server-side, so two accounts on one machine stay independent.
 */
final class ResolveDeviceId
{
    public function run(): string
    {
        $cached = AppSettings::deviceId();

        if ($cached !== null && Str::isUlid($cached)) {
            return $cached;
        }

        $id = Str::ulid()->toBase32();

        AppSettings::setDeviceId($id);

        return $id;
    }
}
