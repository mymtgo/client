<?php

namespace App\Actions\Auth;

use App\Exceptions\AuthExchangeException;
use App\Facades\AppSettings;
use Illuminate\Support\Facades\Log;

/**
 * The mymtgo://oauth/callback endpoint of the PKCE flow. Any other deep
 * link is ignored; a missing code or a state mismatch (CSRF) aborts before
 * the code is ever exchanged.
 */
final class HandleOAuthCallback
{
    public function __construct(
        private ExchangeAuthorizationCode $exchange,
        private CloseAuthWindowOpenMain $swapWindows,
    ) {}

    public function run(string $url): bool
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? null) !== 'mymtgo'
            || trim(($parts['host'] ?? '').($parts['path'] ?? ''), '/') !== 'oauth/callback') {
            return false;
        }

        parse_str($parts['query'] ?? '', $query);
        $code = $query['code'] ?? null;
        $state = $query['state'] ?? null;

        if (! $code || ! $state || ! hash_equals((string) AppSettings::oauthState(), (string) $state)) {
            Log::warning('OAuth callback rejected: missing code or state mismatch.');

            return false;
        }

        try {
            $this->exchange->run($code);
        } catch (AuthExchangeException $e) {
            Log::error('OAuth callback exchange failed', ['message' => $e->getMessage()]);

            return false;
        }

        $this->swapWindows->run();

        return true;
    }
}
