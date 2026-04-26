<?php

use App\Facades\AppSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    AppSettings::setDeviceId('device-123');
    AppSettings::setApiKey('key-abc');
});

it('returns status JSON from action', function () {
    Http::fake([
        '*/api/status' => Http::response(['status' => 'ok']),
    ]);

    $this->get(route('settings.api-status'))
        ->assertOk()
        ->assertExactJson(['state' => 'ok']);
});

it('passes through noauth result', function () {
    Http::fake([
        '*/api/status' => Http::response([
            'status' => 'noauth',
            'message' => 'API key has expired. Please re-authenticate.',
        ]),
    ]);

    $this->get(route('settings.api-status'))
        ->assertOk()
        ->assertExactJson([
            'state' => 'noauth',
            'message' => 'API key has expired. Please re-authenticate.',
        ]);
});
