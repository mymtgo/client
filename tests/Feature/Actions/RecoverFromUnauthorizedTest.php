<?php

use App\Actions\RecoverFromUnauthorized;
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
    AppSettings::setApiKey('old-key');
    AppSettings::setApiKeyExpiresAt(now()->addDay()->toIso8601String());
});

it('refreshes the bearer and leaves the device key alone when linked', function () {
    app(SyncTokens::class)->store('dead-token', 'refresh-token', 2592000);
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'new-token', 'refresh_token' => 'r2', 'expires_in' => 2592000]),
        '*' => Http::response([], 200),
    ]);

    RecoverFromUnauthorized::run();

    expect(app(SyncTokens::class)->accessToken())->toBe('new-token');
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/devices/register'));
});

it('falls back to device mode when the refresh token has been revoked', function () {
    app(SyncTokens::class)->store('dead-token', 'refresh-token', 2592000);
    // The device key lapsed while the client was linked; the retry that
    // follows needs a live one.
    AppSettings::setApiKeyExpiresAt(now()->subHour()->toIso8601String());
    Http::fake([
        '*/oauth/token' => Http::response(['error' => 'invalid_grant'], 401),
        '*/api/devices/register' => Http::response(['api_key' => 'fresh-key'], 200),
    ]);

    RecoverFromUnauthorized::run();

    expect(app(SyncTokens::class)->linked())->toBeFalse()
        ->and(AppSettings::apiKey())->toBe('fresh-key');
});

it('re-registers the device when not linked', function () {
    app(SyncTokens::class)->clear();
    Http::fake(['*/api/devices/register' => Http::response(['api_key' => 'fresh-key'], 200)]);

    RecoverFromUnauthorized::run();

    expect(AppSettings::apiKey())->toBe('fresh-key');
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/oauth/token'));
});
