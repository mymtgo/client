<?php

use App\Actions\Auth\HandleOAuthCallback;
use App\Events\Auth\AuthCallbackFailed;
use App\Facades\AppSettings;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
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
    Http::fake([
        'https://mymtgo.test/oauth/token' => Http::response([
            'access_token' => 'acc', 'refresh_token' => 'ref', 'expires_in' => 3600,
        ], 200),
        'https://mymtgo.test/api/auth/me' => Http::response(['id' => 1], 200),
    ]);

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

it('dispatches a cancelled failure when the callback carries an error param', function () {
    Event::fake([AuthCallbackFailed::class]);
    Http::fake();

    $ok = app(HandleOAuthCallback::class)->run('mymtgo://oauth/callback?error=access_denied&state=state-1');

    expect($ok)->toBeFalse();
    Http::assertNothingSent(); // never attempts the exchange
    Event::assertDispatched(AuthCallbackFailed::class, fn (AuthCallbackFailed $e) => $e->reason === 'cancelled');
});

it('dispatches a failed event on a state mismatch', function () {
    Event::fake([AuthCallbackFailed::class]);
    Http::fake();

    app(HandleOAuthCallback::class)->run('mymtgo://oauth/callback?code=c&state=WRONG');

    Event::assertDispatched(AuthCallbackFailed::class, fn (AuthCallbackFailed $e) => $e->reason === 'failed');
});

it('dispatches a failed event when the callback has no code', function () {
    Event::fake([AuthCallbackFailed::class]);
    Http::fake();

    app(HandleOAuthCallback::class)->run('mymtgo://oauth/callback?state=state-1');

    Event::assertDispatched(AuthCallbackFailed::class, fn (AuthCallbackFailed $e) => $e->reason === 'failed');
});

it('dispatches a failed event when the exchange fails', function () {
    Event::fake([AuthCallbackFailed::class]);
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    app(HandleOAuthCallback::class)->run('mymtgo://oauth/callback?code=bad&state=state-1');

    Event::assertDispatched(AuthCallbackFailed::class, fn (AuthCallbackFailed $e) => $e->reason === 'failed');
});

it('does not dispatch a failure event for unrelated deep links', function () {
    Event::fake([AuthCallbackFailed::class]);
    Http::fake();

    app(HandleOAuthCallback::class)->run('mymtgo://decks/open/5');

    Event::assertNotDispatched(AuthCallbackFailed::class);
});

it('does not dispatch a failure event on a successful exchange', function () {
    Event::fake([AuthCallbackFailed::class]);
    Http::fake([
        'https://mymtgo.test/oauth/token' => Http::response([
            'access_token' => 'acc', 'refresh_token' => 'ref', 'expires_in' => 3600,
        ], 200),
        'https://mymtgo.test/api/auth/me' => Http::response(['id' => 1], 200),
    ]);

    app(HandleOAuthCallback::class)->run('mymtgo://oauth/callback?code=the-code&state=state-1');

    Event::assertNotDispatched(AuthCallbackFailed::class);
});

it('declares the nativephp broadcast channel so the renderer bridge receives it', function () {
    expect((new AuthCallbackFailed('failed'))->broadcastOn())->toBe(['nativephp']);
});

it('dispatches a failed event for non-cancellation oauth errors', function () {
    Event::fake([AuthCallbackFailed::class]);
    Http::fake();

    $ok = app(HandleOAuthCallback::class)->run('mymtgo://oauth/callback?error=server_error&state=state-1');

    expect($ok)->toBeFalse();
    Http::assertNothingSent();
    Event::assertDispatched(AuthCallbackFailed::class, fn (AuthCallbackFailed $e) => $e->reason === 'failed');
});

it('dispatches a failed event when the callback has no state', function () {
    Event::fake([AuthCallbackFailed::class]);
    Http::fake();

    app(HandleOAuthCallback::class)->run('mymtgo://oauth/callback?code=c');

    Event::assertDispatched(AuthCallbackFailed::class, fn (AuthCallbackFailed $e) => $e->reason === 'failed');
});

it('registers the device with an authed bootstrap call after a successful exchange', function () {
    AppSettings::setDeviceId('01JZZZZZZZZZZZZZZZZZZZZZZZ');
    Http::fake([
        'https://mymtgo.test/oauth/token' => Http::response([
            'access_token' => 'acc', 'refresh_token' => 'ref', 'expires_in' => 3600,
        ], 200),
        'https://mymtgo.test/api/auth/me' => Http::response(['id' => 1], 200),
    ]);

    app(HandleOAuthCallback::class)->run('mymtgo://oauth/callback?code=the-code&state=state-1');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/auth/me')
        && $r->hasHeader('Authorization', 'Bearer acc')
        && $r->hasHeader('X-Device-Id', '01JZZZZZZZZZZZZZZZZZZZZZZZ'));
});

it('still completes the login when the bootstrap call fails', function () {
    Http::fake([
        'https://mymtgo.test/oauth/token' => Http::response([
            'access_token' => 'acc', 'refresh_token' => 'ref', 'expires_in' => 3600,
        ], 200),
        'https://mymtgo.test/api/auth/me' => Http::response(null, 500),
    ]);

    $ok = app(HandleOAuthCallback::class)->run('mymtgo://oauth/callback?code=the-code&state=state-1');

    expect($ok)->toBeTrue();
    expect(AppSettings::oauthTokens()->accessToken)->toBe('acc');
});
