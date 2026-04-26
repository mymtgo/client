<?php

use App\Facades\AppSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

it('registers device then returns fresh status', function () {
    AppSettings::setDeviceId('device-123');

    Http::fake([
        '*/api/devices/register' => Http::response(['api_key' => 'fresh-key']),
        '*/api/status' => Http::response(['status' => 'ok']),
    ]);

    $this->post(route('settings.reauthenticate'))
        ->assertOk()
        ->assertExactJson(['state' => 'ok']);

    expect(AppSettings::apiKey())->toBe('fresh-key');
});

it('returns unreachable when registration fails', function () {
    AppSettings::setDeviceId('device-123');

    Http::fake([
        '*/api/devices/register' => Http::response(['error' => 'down'], 500),
    ]);

    $response = $this->post(route('settings.reauthenticate'))
        ->assertOk()
        ->json();

    expect($response['state'])->toBe('unreachable')
        ->and($response['error'])->toContain('Device registration failed');
});
