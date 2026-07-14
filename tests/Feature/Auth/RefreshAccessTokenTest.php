<?php

use App\Actions\Auth\RefreshAccessToken;
use App\Data\OAuthTokens;
use App\Facades\AppSettings;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::swap(new Factory); // escape the global catch-all fake
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');
    AppSettings::setOauthTokens(new OAuthTokens('old-acc', 'ref-1', now()->subMinute()->toIso8601String()));
});

it('refreshes and replaces the stored tokens on success', function () {
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response([
        'access_token' => 'new-acc', 'refresh_token' => 'ref-2', 'expires_in' => 3600,
    ], 200)]);

    expect(app(RefreshAccessToken::class)->run())->toBeTrue();
    expect(AppSettings::oauthTokens()->accessToken)->toBe('new-acc');
    expect(AppSettings::oauthTokens()->refreshToken)->toBe('ref-2');

    Http::assertSent(fn ($r) => $r->data()['grant_type'] === 'refresh_token'
        && $r->data()['refresh_token'] === 'ref-1'
        && ! array_key_exists('client_secret', $r->data()));
});

it('clears the session and returns false when the refresh token is rejected', function () {
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response(['error' => 'invalid_grant'], 401)]);

    expect(app(RefreshAccessToken::class)->run())->toBeFalse();
    expect(AppSettings::oauthTokens())->toBeNull();
});

it('returns false with no round trip when no refresh token is stored', function () {
    AppSettings::clearOauthTokens();
    Http::fake();

    expect(app(RefreshAccessToken::class)->run())->toBeFalse();
    Http::assertNothingSent();
});

it('sends the device id header on the refresh request', function () {
    AppSettings::setDeviceId('01JZZZZZZZZZZZZZZZZZZZZZZZ');
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response([
        'access_token' => 'new-acc', 'refresh_token' => 'ref-2', 'expires_in' => 3600,
    ], 200)]);

    app(RefreshAccessToken::class)->run();

    Http::assertSent(fn ($request) => $request->hasHeader('X-Device-Id', '01JZZZZZZZZZZZZZZZZZZZZZZZ'));
});
