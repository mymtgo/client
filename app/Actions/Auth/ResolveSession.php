<?php

namespace App\Actions\Auth;

use App\Enums\SessionState;
use App\Facades\AppSettings;
use Illuminate\Support\Carbon;

/**
 * The boot gate: stored tokens → Authenticated; near-expiry tokens are
 * refreshed in place first; nothing (or a failed refresh) → Unauthenticated
 * and the auth window is the only thing the user sees.
 */
final class ResolveSession
{
    /** Refresh proactively when the token is within this many seconds of expiry. */
    private const EXPIRY_SKEW_SECONDS = 60;

    public function __construct(private RefreshAccessToken $refresh) {}

    public function run(): SessionState
    {
        $tokens = AppSettings::oauthTokens();

        if ($tokens === null) {
            return SessionState::Unauthenticated;
        }

        $expiresSoon = Carbon::parse($tokens->expiresAt)
            ->subSeconds(self::EXPIRY_SKEW_SECONDS)
            ->isPast();

        if (! $expiresSoon) {
            return SessionState::Authenticated;
        }

        return $this->refresh->run()
            ? SessionState::Authenticated
            : SessionState::Unauthenticated;
    }
}
