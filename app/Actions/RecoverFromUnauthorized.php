<?php

namespace App\Actions;

use App\Actions\Sync\Auth\RefreshAccessToken;
use App\Exceptions\Sync\NotLinkedException;
use App\Services\Sync\SyncTokens;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a 401 from the API means depends on which credential was sent. A
 * linked client sent its bearer, so the token is dead: refresh it, and if
 * the refresh token itself has been revoked the credentials are cleared and
 * the next request goes out in device mode. An unlinked client sent its
 * device key, so re-register it as before. Every 401 remedy in the app
 * routes through here so none of them loops a dead bearer.
 */
class RecoverFromUnauthorized
{
    public static function run(): void
    {
        $tokens = app(SyncTokens::class);

        if (! $tokens->linked()) {
            RegisterDevice::run();

            return;
        }

        try {
            app(RefreshAccessToken::class)->run();
        } catch (NotLinkedException) {
            // Refresh token revoked; credentials are already cleared. The
            // retry that follows this call sends the device key, so make
            // sure one exists.
            RegisterDevice::ensureFresh();
        } catch (Throwable $e) {
            Log::warning('Unauthorized recovery: token refresh failed.', ['error' => $e->getMessage()]);
        }
    }
}
