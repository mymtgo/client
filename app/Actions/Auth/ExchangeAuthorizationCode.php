<?php

namespace App\Actions\Auth;

use App\Data\OAuthTokens;
use App\Exceptions\AuthExchangeException;
use App\Facades\AppSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Swap the authorization code + stashed PKCE verifier for tokens. Public
 * client — no client secret exists, none is ever sent. Token values are
 * never logged.
 */
final class ExchangeAuthorizationCode
{
    public function run(string $code): OAuthTokens
    {
        $verifier = AppSettings::pkceVerifier();

        if (! $verifier) {
            throw new AuthExchangeException('Missing PKCE verifier for token exchange.');
        }

        $response = Http::asForm()->post(rtrim(config('mymtgo_api.url'), '/').'/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => config('mymtgo_api.oauth_client_id'),
            'redirect_uri' => BuildAuthorizeUrl::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $verifier,
        ]);

        if (! $response->successful()) {
            Log::error('OAuth code exchange failed', ['status' => $response->status()]);

            throw new AuthExchangeException('Token exchange returned '.$response->status());
        }

        $tokens = OAuthTokens::fromTokenResponse($response->json());

        AppSettings::setOauthTokens($tokens);
        AppSettings::setPkceVerifier(null);
        AppSettings::setOauthState(null);

        return $tokens;
    }
}
