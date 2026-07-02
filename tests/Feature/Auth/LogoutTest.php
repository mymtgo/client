<?php

use App\Actions\Auth\Logout;
use App\Data\OAuthTokens;
use App\Facades\AppSettings;

it('clears stored tokens and reopens the auth window on logout', function () {
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');
    AppSettings::setOauthTokens(new OAuthTokens('acc', 'ref', now()->addHour()->toIso8601String()));

    app(Logout::class)->run();

    expect(AppSettings::oauthTokens())->toBeNull();
    // OpenAuthWindow ran: a fresh PKCE stash exists for the next sign-in.
    expect(AppSettings::pkceVerifier())->not->toBeNull();
});
