<?php

use App\Actions\RegisterDevice;
use App\Facades\AppSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Drop the global Http::fake() stub from Pest.php so test-specific stubs win.
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    AppSettings::setOffline(true);
    AppSettings::setDeviceId('device-freshness-test');
});

it('re-registers when the key has expired', function () {
    AppSettings::setApiKey('stale-key');
    AppSettings::setApiKeyExpiresAt(now()->subHour()->toIso8601String());

    Http::fake([
        '*/api/devices/register' => Http::response(['api_key' => 'fresh-key'], 200),
    ]);

    RegisterDevice::ensureFresh();

    expect(RegisterDevice::retrieveKey())->toBe('fresh-key');
});

it('does not re-register while the key is still valid', function () {
    AppSettings::setApiKey('good-key');
    AppSettings::setApiKeyExpiresAt(now()->addDay()->toIso8601String());

    Http::preventStrayRequests();

    RegisterDevice::ensureFresh();

    expect(RegisterDevice::retrieveKey())->toBe('good-key');
});

it('keeps card identity requests working past the key lifetime while offline', function () {
    AppSettings::setApiKey('stale-key');
    AppSettings::setApiKeyExpiresAt(now()->subHour()->toIso8601String());

    Http::fake([
        '*/api/devices/register' => Http::response(['api_key' => 'fresh-key'], 200),
        '*/api/cards' => Http::response([], 200),
    ]);

    Http::mymtgoReference()->post('/api/cards', ['ids' => [], 'tokens' => []]);

    Http::assertSent(fn ($request) => $request->url() === config('mymtgo_api.url').'/api/cards'
        && $request->header('X-Api-Key')[0] === 'fresh-key');
});
