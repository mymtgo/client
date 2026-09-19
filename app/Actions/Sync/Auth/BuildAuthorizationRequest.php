<?php

declare(strict_types=1);

namespace App\Actions\Sync\Auth;

use App\Facades\AppSettings;
use Illuminate\Support\Str;

/**
 * Builds the server's PKCE authorization URL. S256 challenge derivation
 * matches RFC 7636. The redirect is the API's own callback page
 * (sync_client.oauth.redirect_uri), which forwards to this app's deep link,
 * so the verifier and state are stashed in settings for the OpenedFromURL
 * callback to pick up: unlike the old loopback listener there is no process
 * waiting in memory for the browser to come back.
 */
class BuildAuthorizationRequest
{
    public function run(): string
    {
        $verifier = Str::random(128);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = Str::random(40);

        AppSettings::setSyncOauthVerifier($verifier);
        AppSettings::setSyncOauthState($state);

        return config('mymtgo_api.url').'/oauth/authorize?'.http_build_query([
            'client_id' => config('sync_client.oauth.client_id'),
            'redirect_uri' => config('sync_client.oauth.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }
}
