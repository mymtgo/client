<?php

declare(strict_types=1);

namespace App\Actions\Sync\Auth;

use App\Actions\Sync\AttestKnownAccounts;
use App\Actions\Tray\FocusOrOpenMainWindow;
use App\Facades\AppSettings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The deep-link end of the PKCE flow, fed by NativePHP's OpenedFromURL
 * event. The browser lands on the API's callback page first, which forwards
 * the same query here (see sync_client.oauth). Any other deep link is ignored. A missing
 * code or a state mismatch (CSRF) aborts before the code is ever exchanged;
 * the stashed verifier and state are one-time and cleared on every genuine
 * outcome, success or refusal, so a replayed redirect can do nothing.
 */
class HandleSyncOauthCallback
{
    public function __construct(private ExchangeAuthorizationCode $exchange) {}

    public function run(string $url): bool
    {
        $parts = parse_url($url);
        $expected = parse_url((string) config('sync_client.oauth.deeplink_uri'));

        if (($parts['scheme'] ?? null) !== ($expected['scheme'] ?? null)
            || trim(($parts['host'] ?? '').($parts['path'] ?? ''), '/') !== trim(($expected['host'] ?? '').($expected['path'] ?? ''), '/')) {
            return false;
        }

        parse_str($parts['query'] ?? '', $query);

        if (isset($query['error'])) {
            Log::warning('Sync device link: consent screen returned an error.', ['error' => $query['error']]);
            $this->forget();

            return false;
        }

        $code = $query['code'] ?? null;
        $state = $query['state'] ?? null;
        $expectedState = AppSettings::syncOauthState();
        $verifier = AppSettings::syncOauthVerifier();

        if (! is_string($code) || ! is_string($state)
            || $expectedState === null || $verifier === null
            || ! hash_equals($expectedState, $state)) {
            Log::warning('Sync device link: callback rejected, missing code or state mismatch.');

            return false;
        }

        $this->forget();

        try {
            $this->exchange->run($code, $verifier);
        } catch (Throwable $e) {
            Log::error('Sync device link: token exchange failed.', ['error' => $e->getMessage()]);

            return false;
        }

        // Freshly linked: tell the API about every MTGO login this client
        // already knows, so the owner's profile can be published at once.
        ['confirmed' => $confirmed, 'owed' => $owed] = AttestKnownAccounts::run();

        Log::info('Sync device link: linked, attested known accounts.', [
            'confirmed' => $confirmed,
            'owed' => $owed,
        ]);

        FocusOrOpenMainWindow::run();

        return true;
    }

    private function forget(): void
    {
        AppSettings::setSyncOauthVerifier(null);
        AppSettings::setSyncOauthState(null);
    }
}
