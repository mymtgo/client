<?php

use App\Facades\AppSettings;
use App\Services\Sync\SyncTokens;
use Illuminate\Support\Facades\Http;

// The Feature suite's global beforeEach registers a blanket Http::fake();
// reset it so each test's own fake is the only one in play.
beforeEach(function () {
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());

    AppSettings::setDeviceId('test-device');
    AppSettings::setApiKey('test-key');
    AppSettings::setApiKeyExpiresAt(now()->addDay()->toIso8601String());
});

it('sends the bearer and no device headers once linked', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    Http::fake(['*' => Http::response([], 200)]);

    Http::mymtgoReference()->post('/api/matches/report', []);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer access-token')
        && ! $request->hasHeader('X-Device-Id')
        && ! $request->hasHeader('X-Api-Key'));
});

it('does not touch the device key while linked, even when it has expired', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    AppSettings::setApiKeyExpiresAt(now()->subHour()->toIso8601String());
    Http::fake(['*' => Http::response([], 200)]);

    Http::mymtgoReference()->post('/api/cards', []);

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/devices/register'));
});

it('sends device headers as before when not linked', function () {
    app(SyncTokens::class)->clear();
    Http::fake(['*' => Http::response([], 200)]);

    Http::mymtgoReference()->post('/api/matches/report', []);

    Http::assertSent(fn ($request) => $request->hasHeader('X-Device-Id', 'test-device')
        && $request->hasHeader('X-Api-Key', 'test-key')
        && ! $request->hasHeader('Authorization'));
});

it('uses the stored token without refreshing while offline', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 60);
    AppSettings::setOffline(true);
    Http::fake(['*' => Http::response([], 200)]);

    Http::mymtgoReference()->post('/api/cards', []);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer access-token'));
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/oauth/token'));
});

it('refreshes an expiring token before sending when online', function () {
    app(SyncTokens::class)->store('old-token', 'refresh-token', 60);
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'new-token', 'refresh_token' => 'r2', 'expires_in' => 2592000]),
        '*' => Http::response([], 200),
    ]);

    Http::mymtgoReference()->post('/api/cards', []);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/cards')
        && $request->hasHeader('Authorization', 'Bearer new-token'));
});
