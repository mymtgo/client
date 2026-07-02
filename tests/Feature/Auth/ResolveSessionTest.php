<?php

use App\Actions\Auth\ResolveSession;
use App\Data\OAuthTokens;
use App\Enums\SessionState;
use App\Facades\AppSettings;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::swap(new Factory); // escape the global catch-all fake
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');
});

it('is Unauthenticated with no stored tokens', function () {
    expect(app(ResolveSession::class)->run())->toBe(SessionState::Unauthenticated);
});

it('is Authenticated when the access token is comfortably valid', function () {
    AppSettings::setOauthTokens(new OAuthTokens('acc', 'ref', now()->addHour()->toIso8601String()));

    expect(app(ResolveSession::class)->run())->toBe(SessionState::Authenticated);
});

it('silently refreshes a near-expiry token and stays Authenticated', function () {
    AppSettings::setOauthTokens(new OAuthTokens('acc', 'ref', now()->addSeconds(10)->toIso8601String()));
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response([
        'access_token' => 'fresh', 'refresh_token' => 'ref2', 'expires_in' => 3600,
    ], 200)]);

    expect(app(ResolveSession::class)->run())->toBe(SessionState::Authenticated);
    expect(AppSettings::oauthTokens()->accessToken)->toBe('fresh');
});

it('is Unauthenticated when a near-expiry token fails to refresh', function () {
    AppSettings::setOauthTokens(new OAuthTokens('acc', 'ref', now()->subMinute()->toIso8601String()));
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response([], 401)]);

    expect(app(ResolveSession::class)->run())->toBe(SessionState::Unauthenticated);
});
