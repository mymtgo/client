<?php

use App\Actions\Leagues\FetchOpponentScouting;
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

    $result = FetchOpponentScouting::run('Foo', 'CModern');

    expect($result)->toBe([
        'league' => ['uuid' => 'arch-uuid-1', 'name' => 'Izzet Phoenix', 'colors' => 'U,R'],
        'tracked' => null,
        'observed' => null,
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

    expect(FetchOpponentScouting::run('Bar', 'CPioneer'))
        ->toBe([
            'league' => ['uuid' => 'unknown-uuid', 'name' => 'Mystery Brew', 'colors' => null],
            'tracked' => null,
            'observed' => null,
        ]);
});

it('returns null on 404 response', function () {
    Http::fake([
        '*/api/players' => Http::response(['message' => 'not found'], 404),
    ]);

    expect(FetchOpponentScouting::run('Ghost', 'CLegacy'))->toBeNull();
});

it('returns null when no source carries an archetype', function () {
    Http::fake([
        '*/api/players' => Http::response([
            'data' => [
                'league_result' => [
                    'archetype' => null,
                ],
            ],
        ]),
    ]);

    expect(FetchOpponentScouting::run('NoArch', 'CModern'))->toBeNull();
});

it('returns tracked and observed archetypes alongside the league one', function () {
    Http::fake([
        '*/api/players' => Http::response([
            'data' => [
                'league_result' => null,
                'tracked' => ['archetype' => ['uuid' => 'tracked-uuid', 'name' => 'Boros Energy', 'slug' => 'boros-energy']],
                'observed' => ['archetype' => ['uuid' => 'observed-uuid', 'name' => 'Amulet Titan', 'slug' => 'amulet-titan']],
            ],
        ]),
    ]);

    expect(FetchOpponentScouting::run('Both', 'CModern'))->toBe([
        'league' => null,
        'tracked' => ['uuid' => 'tracked-uuid', 'name' => 'Boros Energy', 'colors' => null],
        'observed' => ['uuid' => 'observed-uuid', 'name' => 'Amulet Titan', 'colors' => null],
    ]);
});

it('returns null when http throws', function () {
    Http::fake(function () {
        throw new ConnectionException('boom');
    });

    expect(FetchOpponentScouting::run('Anyone', 'CModern'))->toBeNull();
});
