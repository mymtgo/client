<?php

use App\Actions\Auth\BuildAuthorizeUrl;
use App\Facades\AppSettings;

it('builds an authorize URL with PKCE params and stashes the verifier + state', function () {
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');

    $url = app(BuildAuthorizeUrl::class)->run();

    expect($url)->toStartWith('https://mymtgo.test/oauth/authorize?');

    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    expect($q['client_id'])->toBe('client-abc');
    expect($q['response_type'])->toBe('code');
    expect($q['redirect_uri'])->toBe('mymtgo://oauth/callback');
    expect($q['code_challenge_method'])->toBe('S256');
    expect($q['code_challenge'])->not->toBeEmpty();
    expect($q['state'])->toBe(AppSettings::oauthState());

    // the verifier matching this challenge was stashed for the exchange
    $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', AppSettings::pkceVerifier(), true)), '+/', '-_'), '=');
    expect($q['code_challenge'])->toBe($expectedChallenge);
});
