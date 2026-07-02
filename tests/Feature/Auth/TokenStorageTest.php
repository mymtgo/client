<?php

use App\Data\OAuthTokens;
use App\Facades\AppSettings;

it('round-trips an OAuthTokens set through AppSettings', function () {
    $tokens = new OAuthTokens(
        accessToken: 'acc-123',
        refreshToken: 'ref-456',
        expiresAt: now()->addHour()->toIso8601String(),
    );

    AppSettings::setOauthTokens($tokens);
    $out = AppSettings::oauthTokens();

    expect($out)->toBeInstanceOf(OAuthTokens::class);
    expect($out->accessToken)->toBe('acc-123');
    expect($out->refreshToken)->toBe('ref-456');
});

it('returns null when no tokens are stored', function () {
    expect(AppSettings::oauthTokens())->toBeNull();
});

it('builds an OAuthTokens from a token response with expires_in seconds', function () {
    $tokens = OAuthTokens::fromTokenResponse([
        'access_token' => 'acc',
        'refresh_token' => 'ref',
        'expires_in' => 3600,
    ]);

    expect($tokens->accessToken)->toBe('acc');
    expect(now()->parse($tokens->expiresAt)->isFuture())->toBeTrue();
});

it('stashes and reads the transient PKCE verifier + state', function () {
    AppSettings::setPkceVerifier('the-verifier');
    AppSettings::setOauthState('the-state');

    expect(AppSettings::pkceVerifier())->toBe('the-verifier');
    expect(AppSettings::oauthState())->toBe('the-state');
});

it('clears all token material at once', function () {
    AppSettings::setOauthTokens(new OAuthTokens('a', 'r', now()->addHour()->toIso8601String()));

    AppSettings::clearOauthTokens();

    expect(AppSettings::oauthTokens())->toBeNull();
});
