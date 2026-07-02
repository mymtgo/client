<?php

use App\Actions\Auth\HandleOAuthCallback;
use App\Facades\AppSettings;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::swap(new Factory); // escape the global catch-all fake
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');
    AppSettings::setPkceVerifier('verifier-1');
    AppSettings::setOauthState('state-1');
});

it('ignores deep links that are not the oauth callback', function () {
    Http::fake();

    expect(app(HandleOAuthCallback::class)->run('mymtgo://decks/open/5'))->toBeFalse();
    expect(AppSettings::pkceVerifier())->toBe('verifier-1'); // untouched
    Http::assertNothingSent();
});

it('rejects a callback whose state does not match the stash', function () {
    Http::fake();

    $ok = app(HandleOAuthCallback::class)->run('mymtgo://oauth/callback?code=c&state=WRONG');

    expect($ok)->toBeFalse();
    Http::assertNothingSent(); // never exchanged the code
});

it('rejects a callback with no code', function () {
    Http::fake();

    expect(app(HandleOAuthCallback::class)->run('mymtgo://oauth/callback?state=state-1'))->toBeFalse();
    Http::assertNothingSent();
});

it('exchanges the code and stores tokens on a matching-state callback', function () {
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response([
        'access_token' => 'acc', 'refresh_token' => 'ref', 'expires_in' => 3600,
    ], 200)]);

    $ok = app(HandleOAuthCallback::class)->run('mymtgo://oauth/callback?code=the-code&state=state-1');

    expect($ok)->toBeTrue();
    expect(AppSettings::oauthTokens()->accessToken)->toBe('acc');
});

it('returns false when the exchange itself fails', function () {
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $ok = app(HandleOAuthCallback::class)->run('mymtgo://oauth/callback?code=bad&state=state-1');

    expect($ok)->toBeFalse();
    expect(AppSettings::oauthTokens())->toBeNull();
});
