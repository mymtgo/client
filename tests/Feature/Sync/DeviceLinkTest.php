<?php

use App\Actions\Sync\Auth\BuildAuthorizationRequest;
use App\Actions\Sync\Auth\EnsureAccessToken;
use App\Actions\Sync\Auth\ExchangeAuthorizationCode;
use App\Actions\Sync\Auth\HandleSyncOauthCallback;
use App\Actions\Sync\Auth\RefreshAccessToken;
use App\Exceptions\Sync\NotLinkedException;
use App\Facades\AppSettings;
use App\Jobs\AttestAccount;
use App\Models\Account;
use App\Services\Sync\SyncTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Native\Desktop\Events\App\OpenedFromURL;

uses(RefreshDatabase::class);

// The Feature test suite's global beforeEach registers a blanket Http::fake()
// that matches every URL and wins over any later Http::fake([...]) pattern
// (Laravel evaluates fakes in registration order, first match wins). Reset
// stubCallbacks here so each test's own fake is the only one in play, the
// same reset used elsewhere in this suite (see DetermineDeckArchetypeTest).
beforeEach(function () {
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());
});

it('builds an authorize url whose challenge is derived from the stashed verifier', function () {
    $url = app(BuildAuthorizationRequest::class)->run();

    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    $verifier = AppSettings::syncOauthVerifier();
    $expected = rtrim(strtr(base64_encode(hash('sha256', (string) $verifier, true)), '+/', '-_'), '=');

    expect($query['code_challenge'])->toBe($expected)
        ->and($query['code_challenge_method'])->toBe('S256')
        // No scope: one first-party client has nothing to partition, and the
        // API no longer checks one (API spec 2026-09-09, Scopes).
        ->and(array_key_exists('scope', $query))->toBeFalse()
        // The registered redirect is the API's callback page, which forwards
        // to the deep link; the app never asks the server for the scheme.
        ->and($query['redirect_uri'])->toBe(config('sync_client.oauth.redirect_uri'))
        ->and($query['state'])->toBe(AppSettings::syncOauthState());
});

it('links the device from the deep-link callback and clears the one-time stash', function () {
    AppSettings::setSyncOauthVerifier('verifier');
    AppSettings::setSyncOauthState('goodstate');
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'at1', 'refresh_token' => 'rt1', 'expires_in' => 2592000]),
    ]);

    $handled = app(HandleSyncOauthCallback::class)->run('mymtgo://oauth/callback?code=abc123&state=goodstate');

    expect($handled)->toBeTrue()
        ->and(app(SyncTokens::class)->accessToken())->toBe('at1')
        ->and(AppSettings::syncOauthVerifier())->toBeNull()
        ->and(AppSettings::syncOauthState())->toBeNull();

    Http::assertSent(function ($request) {
        return $request['code'] === 'abc123'
            && $request['code_verifier'] === 'verifier'
            && $request['redirect_uri'] === config('sync_client.oauth.redirect_uri');
    });
});

it('rejects a state mismatch before the code is ever exchanged', function () {
    AppSettings::setSyncOauthVerifier('verifier');
    AppSettings::setSyncOauthState('goodstate');
    Http::fake();

    $handled = app(HandleSyncOauthCallback::class)->run('mymtgo://oauth/callback?code=abc123&state=evilstate');

    expect($handled)->toBeFalse()
        ->and(app(SyncTokens::class)->linked())->toBeFalse();

    Http::assertNothingSent();
});

it('ignores deep links that are not the oauth callback', function () {
    AppSettings::setSyncOauthVerifier('verifier');
    AppSettings::setSyncOauthState('goodstate');
    Http::fake();

    $handled = app(HandleSyncOauthCallback::class)->run('mymtgo://somewhere/else?code=abc123&state=goodstate');

    expect($handled)->toBeFalse()
        ->and(AppSettings::syncOauthVerifier())->toBe('verifier');

    Http::assertNothingSent();
});

it('drops the stash without exchanging when the consent screen returns an error', function () {
    AppSettings::setSyncOauthVerifier('verifier');
    AppSettings::setSyncOauthState('goodstate');
    Http::fake();

    $handled = app(HandleSyncOauthCallback::class)->run('mymtgo://oauth/callback?error=access_denied&state=goodstate');

    expect($handled)->toBeFalse()
        ->and(AppSettings::syncOauthVerifier())->toBeNull()
        ->and(AppSettings::syncOauthState())->toBeNull();

    Http::assertNothingSent();
});

it('handles the NativePHP OpenedFromURL event end to end', function () {
    AppSettings::setSyncOauthVerifier('verifier');
    AppSettings::setSyncOauthState('goodstate');
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'at1', 'refresh_token' => 'rt1', 'expires_in' => 2592000]),
    ]);

    event(new OpenedFromURL('mymtgo://oauth/callback?code=abc123&state=goodstate'));

    expect(app(SyncTokens::class)->linked())->toBeTrue();
});

it('exchanges the code and stores rotated tokens', function () {
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'at1', 'refresh_token' => 'rt1', 'expires_in' => 2592000]),
    ]);

    app(ExchangeAuthorizationCode::class)->run('code', 'verifier');

    expect(app(SyncTokens::class)->accessToken())->toBe('at1')
        ->and(app(SyncTokens::class)->linked())->toBeTrue();

    Http::assertSent(function ($request) {
        return $request['grant_type'] === 'authorization_code'
            && $request['code_verifier'] === 'verifier';
    });
});

it('refreshes and rotates both tokens', function () {
    app(SyncTokens::class)->store('old-at', 'old-rt', 60);
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'at2', 'refresh_token' => 'rt2', 'expires_in' => 2592000]),
    ]);

    app(RefreshAccessToken::class)->run();

    expect(app(SyncTokens::class)->accessToken())->toBe('at2')
        ->and(app(SyncTokens::class)->refreshToken())->toBe('rt2');
});

it('clears credentials and reports unlinked when the refresh is refused', function () {
    app(SyncTokens::class)->store('old-at', 'revoked-rt', 60);
    Http::fake(['*/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(fn () => app(RefreshAccessToken::class)->run())->toThrow(NotLinkedException::class)
        ->and(app(SyncTokens::class)->linked())->toBeFalse();
});

it('leaves credentials intact and rethrows on a 400 that is not invalid_grant', function () {
    app(SyncTokens::class)->store('old-at', 'old-rt', 60);
    Http::fake(['*/oauth/token' => Http::response(['error' => 'invalid_request'], 400)]);

    expect(fn () => app(RefreshAccessToken::class)->run())->toThrow(RequestException::class);

    expect(app(SyncTokens::class)->accessToken())->toBe('old-at')
        ->and(app(SyncTokens::class)->refreshToken())->toBe('old-rt')
        ->and(app(SyncTokens::class)->linked())->toBeTrue();
});

it('ensures a token by refreshing one that is near expiry', function () {
    app(SyncTokens::class)->store('stale', 'rt', 60 * 60); // expires within 24h
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'fresh', 'refresh_token' => 'rt2', 'expires_in' => 2592000]),
    ]);

    expect(app(EnsureAccessToken::class)->run())->toBe('fresh');
});

it('throws NotLinked when nothing is stored', function () {
    app(SyncTokens::class)->clear();

    expect(fn () => app(EnsureAccessToken::class)->run())->toThrow(NotLinkedException::class);
});

it('attests every account with a login id once the device is linked', function () {
    Queue::fake();
    Account::factory()->create(['username' => 'keyed', 'login_id' => 3022021]);
    Account::factory()->create(['username' => 'unkeyed', 'login_id' => null]);
    AppSettings::setSyncOauthVerifier('verifier');
    AppSettings::setSyncOauthState('goodstate');
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'at1', 'refresh_token' => 'rt1', 'expires_in' => 2592000]),
    ]);

    app(HandleSyncOauthCallback::class)->run('mymtgo://oauth/callback?code=abc123&state=goodstate');

    Queue::assertPushed(AttestAccount::class, 1);
});

it('registers the API callback page as the redirect, not the deep link', function () {
    expect(config('sync_client.oauth.redirect_uri'))
        ->toBe(rtrim((string) config('mymtgo_api.url'), '/').'/oauth/desktop/callback')
        ->and(config('sync_client.oauth.deeplink_uri'))->toBe('mymtgo://oauth/callback');
});

it('ignores a deep link that is not the OAuth callback', function () {
    AppSettings::setSyncOauthVerifier('verifier');
    AppSettings::setSyncOauthState('goodstate');
    Http::fake();

    expect(app(HandleSyncOauthCallback::class)->run('mymtgo://decks/1?code=abc123&state=goodstate'))->toBeFalse();

    Http::assertNothingSent();
});
