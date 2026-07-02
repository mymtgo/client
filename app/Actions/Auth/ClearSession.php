<?php

namespace App\Actions\Auth;

use App\Facades\AppSettings;

/**
 * Wipe all local token material — logout, revoked device, or failed
 * refresh. The next boot's ResolveSession routes back to the auth window.
 */
final class ClearSession
{
    public function run(): void
    {
        AppSettings::clearOauthTokens();
        AppSettings::setPkceVerifier(null);
        AppSettings::setOauthState(null);
    }
}
