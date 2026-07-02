<?php

use App\Data\OAuthTokens;
use App\Facades\AppSettings;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::swap(new Factory); // escape the global catch-all fake
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');
    AppSettings::setOauthTokens(new OAuthTokens('acc-1', 'ref-1', now()->addHour()->toIso8601String()));
});

it('attaches the Bearer access token and base url', function () {
    Http::fake(['https://mymtgo.test/*' => Http::response([], 200)]);

    Http::mymtgoAuthed()->post('/api/matches', ['x' => 1]);

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer acc-1')
        && str_starts_with($r->url(), 'https://mymtgo.test/api/matches'));
});

it('refreshes once and retries on a 401', function () {
    Http::fake([
        'https://mymtgo.test/oauth/token' => Http::response([
            'access_token' => 'acc-2', 'refresh_token' => 'ref-2', 'expires_in' => 3600,
        ], 200),
        'https://mymtgo.test/api/matches' => Http::sequence()
            ->push([], 401)
            ->push(['ok' => true], 200),
    ]);

    $response = Http::mymtgoAuthed()->post('/api/matches', ['x' => 1]);

    expect($response->status())->toBe(200);
    expect(AppSettings::oauthTokens()->accessToken)->toBe('acc-2');
    Http::assertSentCount(3); // original + token refresh + retry
});

it('returns the 401 unchanged when the refresh itself fails', function () {
    Http::fake([
        'https://mymtgo.test/oauth/token' => Http::response(['error' => 'invalid_grant'], 401),
        'https://mymtgo.test/api/matches' => Http::response([], 401),
    ]);

    $response = Http::mymtgoAuthed()->post('/api/matches', ['x' => 1]);

    expect($response->status())->toBe(401);
    expect(AppSettings::oauthTokens())->toBeNull(); // failed refresh cleared the session
});
