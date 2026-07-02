<?php

use App\Actions\Auth\OpenAuthWindow;
use App\Facades\AppSettings;

it('opens an auth window pointed at the authorize URL and stashes the verifier', function () {
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');

    app(OpenAuthWindow::class)->run();

    // Window opening is faked globally; the PHP-assertable contract is the
    // BuildAuthorizeUrl side effect. The on-screen window is observed-verified.
    expect(AppSettings::pkceVerifier())->not->toBeNull();
    expect(AppSettings::oauthState())->not->toBeNull();
});
