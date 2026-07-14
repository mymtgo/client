<?php

namespace App\Actions\Device;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Post-login bootstrap ping. The API registers/touches the device row from
 * the X-Device-Id header on any authenticated request; this fires the
 * first one immediately after sign-in so the device is tied to the user
 * at login rather than whenever the first data push happens. Failures are
 * logged, never fatal — the next authed call registers the device anyway.
 */
final class RegisterDeviceOnApi
{
    public function run(): void
    {
        try {
            Http::mymtgoAuthed()->get('/api/auth/me');
        } catch (\Throwable $e) {
            Log::warning('Post-login device registration ping failed', ['message' => $e->getMessage()]);
        }
    }
}
