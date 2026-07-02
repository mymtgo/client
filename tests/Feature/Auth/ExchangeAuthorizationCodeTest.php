<?php

use App\Actions\Auth\ExchangeAuthorizationCode;
use App\Exceptions\AuthExchangeException;
use App\Facades\AppSettings;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::swap(new Factory); // escape the global catch-all fake
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');
    AppSettings::setPkceVerifier('stashed-verifier');
});

it('exchanges a code for tokens and stores them (no client secret sent)', function () {
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response([
        'access_token' => 'acc-1', 'refresh_token' => 'ref-1', 'expires_in' => 3600,
    ], 200)]);

    $tokens = app(ExchangeAuthorizationCode::class)->run('the-code');

    expect($tokens->accessToken)->toBe('acc-1');
    expect(AppSettings::oauthTokens()->refreshToken)->toBe('ref-1');
    expect(AppSettings::pkceVerifier())->toBeNull(); // one-time verifier cleared

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://mymtgo.test/oauth/token'
            && $body['grant_type'] === 'authorization_code'
            && $body['code'] === 'the-code'
            && $body['code_verifier'] === 'stashed-verifier'
            && $body['client_id'] === 'client-abc'
            && ! array_key_exists('client_secret', $body);
    });
});

it('throws AuthExchangeException on a non-2xx token response', function () {
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    app(ExchangeAuthorizationCode::class)->run('bad-code');
})->throws(AuthExchangeException::class);

it('throws when no verifier is stashed', function () {
    AppSettings::setPkceVerifier(null);
    Http::fake();

    app(ExchangeAuthorizationCode::class)->run('the-code');
})->throws(AuthExchangeException::class);
