<?php

use App\Actions\Api\CheckApiStatus;
use App\Facades\AppSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Clear the global Http::fake() from Pest.php so test-specific fakes win.
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    AppSettings::setDeviceId('device-123');
    AppSettings::setApiKey('key-abc');
    AppSettings::setApiKeyExpiresAt(now()->addHour()->toIso8601String());
});

it('returns ok when API responds with status ok', function () {
    Http::fake([
        '*/api/status' => Http::response(['status' => 'ok']),
    ]);

    expect(CheckApiStatus::run())->toBe(['state' => 'ok']);
});

it('returns noauth with message when API responds with noauth', function () {
    Http::fake([
        '*/api/status' => Http::response([
            'status' => 'noauth',
            'message' => 'Device not recognized.',
        ]),
    ]);

    expect(CheckApiStatus::run())->toBe([
        'state' => 'noauth',
        'message' => 'Device not recognized.',
    ]);
});

it('returns unreachable when connection fails', function () {
    Http::fake(function () {
        throw new ConnectionException('cURL error 7: connection refused');
    });

    $result = CheckApiStatus::run();

    expect($result['state'])->toBe('unreachable')
        ->and($result['error'])->toContain('connection refused');
});

it('returns unreachable for non-2xx response', function () {
    Http::fake([
        '*/api/status' => Http::response('Bad gateway', 502),
    ]);

    $result = CheckApiStatus::run();

    expect($result['state'])->toBe('unreachable')
        ->and($result['error'])->toContain('502');
});

it('sends device id and api key headers', function () {
    Http::fake([
        '*/api/status' => Http::response(['status' => 'ok']),
    ]);

    CheckApiStatus::run();

    Http::assertSent(fn ($request) => $request->hasHeader('X-Device-Id', 'device-123')
        && $request->hasHeader('X-Api-Key', 'key-abc')
    );
});
