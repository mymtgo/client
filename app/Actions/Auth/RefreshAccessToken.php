<?php

namespace App\Actions\Auth;

use App\Data\OAuthTokens;
use App\Facades\AppSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Silent token refresh. A rejected refresh token (revoked device, expiry)
 * clears the session — the caller falls back to re-auth.
 */
final class RefreshAccessToken
{
    public function __construct(private ClearSession $clearSession) {}

    public function run(): bool
    {
        $tokens = AppSettings::oauthTokens();

        if ($tokens === null) {
            return false;
        }

        $response = Http::asForm()->post(rtrim(config('mymtgo_api.url'), '/').'/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => config('mymtgo_api.oauth_client_id'),
            'refresh_token' => $tokens->refreshToken,
            'scope' => '',
        ]);

        if (! $response->successful()) {
            Log::warning('OAuth refresh failed — clearing session', ['status' => $response->status()]);
            $this->clearSession->run();

            return false;
        }

        AppSettings::setOauthTokens(OAuthTokens::fromTokenResponse($response->json()));

        return true;
    }
}
