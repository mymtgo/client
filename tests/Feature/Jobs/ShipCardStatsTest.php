<?php

use App\Facades\AppSettings;
use App\Jobs\ShipCardStats;
use App\Models\CardStatShipQueue;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Drop the global Http::fake() stub from Pest.php so test-specific stubs win.
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    AppSettings::setDeviceId('test-device');
    AppSettings::setApiKey('test-key');
});

function enqueueShipRow(array $overrides = []): CardStatShipQueue
{
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->for($match, 'match')->create(['won' => true]);

    return CardStatShipQueue::create(array_merge([
        'game_id' => $game->id,
        'match_id' => $match->id,
        'payload' => json_encode([
            'player_archetype_uuid' => fake()->uuid(),
            'opponent_archetype_uuid' => fake()->uuid(),
            'format' => 'CStandard',
            'won' => true,
            'on_play' => true,
            'is_postboard' => false,
            'played_on' => '2026-05-19',
            'cards' => [['oracle_id' => fake()->uuid()]],
        ]),
        'status' => 'pending',
    ], $overrides));
}

it('marks queue rows sent on 2xx response', function () {
    $row = enqueueShipRow();

    Http::fake([
        '*/api/card-stats/report' => Http::response('', 201),
        '*' => Http::response('', 200),
    ]);

    (new ShipCardStats)->handle();

    expect($row->fresh()->status)->toBe('sent');
    expect($row->fresh()->attempts)->toBe(1);
});

it('sends gzipped body with auth headers', function () {
    enqueueShipRow();

    Http::fake([
        '*/api/card-stats/report' => Http::response('', 201),
        '*' => Http::response('', 200),
    ]);

    (new ShipCardStats)->handle();

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Device-Id', 'test-device')
            && $request->hasHeader('X-Api-Key', 'test-key')
            && $request->hasHeader('Content-Encoding', 'gzip')
            && strlen($request->body()) > 0;
    });
});

it('flips failed rows back to pending with exponential backoff', function () {
    $row = enqueueShipRow();

    Http::fake([
        '*/api/card-stats/report' => Http::response('server error', 500),
        '*' => Http::response('', 200),
    ]);

    (new ShipCardStats)->handle();

    $row->refresh();
    expect($row->status)->toBe('pending');
    expect($row->attempts)->toBe(1);
    expect($row->next_attempt_at)->not->toBeNull();
    expect($row->last_error)->toContain('500');
});

it('marks rows failed after MAX_ATTEMPTS', function () {
    $row = enqueueShipRow(['attempts' => 19, 'next_attempt_at' => now()->subMinute()]);

    Http::fake([
        '*/api/card-stats/report' => Http::response('boom', 500),
        '*' => Http::response('', 200),
    ]);

    (new ShipCardStats)->handle();

    expect($row->fresh()->status)->toBe('failed');
    expect($row->fresh()->attempts)->toBe(20);
});

it('skips rows whose next_attempt_at is in the future', function () {
    $row = enqueueShipRow(['next_attempt_at' => now()->addMinutes(5)]);

    Http::fake([
        '*/api/card-stats/report' => Http::response('', 201),
        '*' => Http::response('', 200),
    ]);

    (new ShipCardStats)->handle();

    expect($row->fresh()->status)->toBe('pending');
    expect($row->fresh()->attempts)->toBe(0);
    Http::assertNothingSent();
});

it('chunks claimed rows into HTTP_CHUNK requests', function () {
    // 150 rows = 2 chunks (100 + 50). Claim limit 200 exceeds total.
    for ($i = 0; $i < 150; $i++) {
        enqueueShipRow();
    }

    Http::fake([
        '*/api/card-stats/report' => Http::response('', 201),
        '*' => Http::response('', 200),
    ]);

    (new ShipCardStats)->handle();

    $requests = 0;
    Http::assertSent(function ($request) use (&$requests) {
        if (str_contains($request->url(), '/api/card-stats/report')) {
            $requests++;
        }

        return true;
    });

    expect($requests)->toBe(2);
    expect(CardStatShipQueue::where('status', 'sent')->count())->toBe(150);
});

it('isolates per-chunk failures so successful chunks remain sent', function () {
    for ($i = 0; $i < 150; $i++) {
        enqueueShipRow();
    }

    // First call returns 201, second returns 500.
    Http::fake([
        '*/api/card-stats/report' => Http::sequence()
            ->push('', 201)
            ->push('boom', 500),
    ]);

    (new ShipCardStats)->handle();

    expect(CardStatShipQueue::where('status', 'sent')->count())->toBe(100);
    expect(CardStatShipQueue::where('status', 'pending')->count())->toBe(50);
});

it('sends body shape that satisfies API validator', function () {
    enqueueShipRow();

    Http::fake([
        '*/api/card-stats/report' => Http::response('', 201),
        '*' => Http::response('', 200),
    ]);

    (new ShipCardStats)->handle();

    Http::assertSent(function ($request) {
        $decoded = gzdecode($request->body());
        expect($decoded)->not->toBeFalse();

        $body = json_decode($decoded, true);
        expect($body)->toHaveKey('games');
        expect($body['games'])->toBeArray()->toHaveCount(1);

        $game = $body['games'][0];
        expect($game)->toHaveKeys([
            'player_archetype_uuid',
            'opponent_archetype_uuid',
            'format',
            'won',
            'on_play',
            'is_postboard',
            'played_on',
            'cards',
        ]);

        expect($game['cards'])->toBeArray()->not->toBeEmpty();

        return true;
    });
});
