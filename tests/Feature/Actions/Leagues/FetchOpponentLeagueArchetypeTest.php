<?php

use App\Actions\Leagues\FetchOpponentLeagueArchetype;
use App\Facades\AppSettings;
use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    AppSettings::setDeviceId('device-123');
    AppSettings::setApiKey('key-abc');
    AppSettings::setApiKeyExpiresAt(now()->addHour()->toIso8601String());
});

it('returns archetype with colors from local archetype lookup on 200', function () {
    Archetype::factory()->create([
        'uuid' => 'arch-uuid-1',
        'name' => 'Izzet Phoenix',
        'format' => 'modern',
        'color_identity' => 'UR',
    ]);

    Http::fake([
        '*/api/players' => Http::response([
            'data' => [
                'player' => 'Foo',
                'league_result' => [
                    'archetype' => [
                        'uuid' => 'arch-uuid-1',
                        'name' => 'Izzet Phoenix',
                        'slug' => 'izzet-phoenix',
                    ],
                ],
            ],
        ]),
    ]);

    $result = FetchOpponentLeagueArchetype::run('Foo', 'CModern');

    expect($result)->toBe([
        'name' => 'Izzet Phoenix',
        'colors' => 'UR',
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/api/players')
        && $request['username'] === 'Foo'
        && $request['format'] === 'modern');
});

it('returns archetype with null colors when local archetype is missing', function () {
    Http::fake([
        '*/api/players' => Http::response([
            'data' => [
                'league_result' => [
                    'archetype' => [
                        'uuid' => 'unknown-uuid',
                        'name' => 'Mystery Brew',
                        'slug' => 'mystery',
                    ],
                ],
            ],
        ]),
    ]);

    expect(FetchOpponentLeagueArchetype::run('Bar', 'CPioneer'))
        ->toBe(['name' => 'Mystery Brew', 'colors' => null]);
});

it('returns null on 404 response', function () {
    Http::fake([
        '*/api/players' => Http::response(['message' => 'not found'], 404),
    ]);

    expect(FetchOpponentLeagueArchetype::run('Ghost', 'CLegacy'))->toBeNull();
});

it('returns null when archetype is missing in payload', function () {
    Http::fake([
        '*/api/players' => Http::response([
            'data' => [
                'league_result' => [
                    'archetype' => null,
                ],
            ],
        ]),
    ]);

    expect(FetchOpponentLeagueArchetype::run('NoArch', 'CModern'))->toBeNull();
});

it('returns null when http throws', function () {
    Http::fake(function () {
        throw new ConnectionException('boom');
    });

    expect(FetchOpponentLeagueArchetype::run('Anyone', 'CModern'))->toBeNull();
});
