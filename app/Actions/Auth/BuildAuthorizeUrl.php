<?php

namespace App\Actions\Auth;

use App\Facades\AppSettings;

/**
 * Assemble the cloud API's /oauth/authorize URL for the PKCE flow and stash
 * the one-time verifier + state for the eventual callback exchange.
 */
final class BuildAuthorizeUrl
{
    public const REDIRECT_URI = 'mymtgo://oauth/callback';

    public function __construct(private GeneratePkcePair $pkce) {}

    public function run(): string
    {
        $pair = $this->pkce->run();

        AppSettings::setPkceVerifier($pair['verifier']);
        AppSettings::setOauthState($pair['state']);

        $query = http_build_query([
            'client_id' => config('mymtgo_api.oauth_client_id'),
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => '',
            'state' => $pair['state'],
            'code_challenge' => $pair['challenge'],
            'code_challenge_method' => 'S256',
        ]);

        return rtrim(config('mymtgo_api.url'), '/').'/oauth/authorize?'.$query;
    }
}
