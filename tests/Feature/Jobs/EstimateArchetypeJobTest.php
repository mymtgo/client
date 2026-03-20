<?php

use App\Events\ArchetypeEstimateUpdated;
use App\Jobs\EstimateArchetypeJob;
use App\Models\Archetype;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Clear the global Http::fake() from Pest.php beforeEach so test-specific
// fakes take priority. Re-add a catch-all '*' in each test's fake array
// to keep NativePHP facades happy.
beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

it('calls API and broadcasts ArchetypeEstimateUpdated when version matches', function () {
    Event::fake();

    Http::fake([
        '*' => Http::response([
            ['uuid' => 'arch-uuid-1', 'confidence' => 0.92],
        ], 200),
    ]);

    $archetype = Archetype::factory()->create([
        'uuid' => 'arch-uuid-1',
        'name' => 'Burn',
        'color_identity' => 'R',
    ]);

    MtgoMatch::factory()->create([
        'token' => 'match-token-1',
        'format' => 'Modern',
    ]);

    $cards = [
        ['mtgo_id' => 12345, 'quantity' => 4],
        ['mtgo_id' => 67890, 'quantity' => 2],
    ];

    Cache::put('archetype_detect:match-token-1:cards', $cards, now()->addHour());
    Cache::put('archetype_detect:match-token-1:version', 3, now()->addHour());
    Cache::put('archetype_detect:match-token-1:player', 'OpponentUser', now()->addHour());

    $job = new EstimateArchetypeJob('match-token-1', 3);
    $job->handle();

    Event::assertDispatched(ArchetypeEstimateUpdated::class, function ($event) use ($archetype) {
        return $event->matchToken === 'match-token-1'
            && $event->playerName === 'OpponentUser'
            && $event->archetypeName === $archetype->name
            && $event->archetypeColorIdentity === 'R'
            && $event->confidence === 92
            && $event->cardsSeen === 2;
    });

    Http::assertSent(function ($request) {
        $sentCards = $request->data()['cards'];
        expect($sentCards)->toHaveCount(2);
        expect($sentCards[0]['mtgo_id'])->toBe(12345);
        expect($sentCards[1]['mtgo_id'])->toBe(67890);

        return true;
    });
});

it('exits when version is stale (superseded by newer job)', function () {
    Event::fake();

    Http::fake(['*' => Http::response('', 200)]);

    MtgoMatch::factory()->create(['token' => 'match-token-2', 'format' => 'Modern']);

    Cache::put('archetype_detect:match-token-2:cards', [
        ['mtgo_id' => 12345, 'quantity' => 1],
    ], now()->addHour());
    Cache::put('archetype_detect:match-token-2:version', 5, now()->addHour());

    $job = new EstimateArchetypeJob('match-token-2', 3); // version 3, current is 5
    $job->handle();

    Event::assertNotDispatched(ArchetypeEstimateUpdated::class);
    Http::assertNothingSent();
});

it('exits when cache is empty', function () {
    Event::fake();

    Http::fake(['*' => Http::response('', 200)]);

    MtgoMatch::factory()->create(['token' => 'match-token-3', 'format' => 'Modern']);

    Cache::put('archetype_detect:match-token-3:version', 1, now()->addHour());
    // No cards key in cache

    $job = new EstimateArchetypeJob('match-token-3', 1);
    $job->handle();

    Event::assertNotDispatched(ArchetypeEstimateUpdated::class);
    Http::assertNothingSent();
});

it('does nothing when match does not exist', function () {
    Event::fake();

    Http::fake(['*' => Http::response('', 200)]);

    Cache::put('archetype_detect:match-token-missing:cards', [
        ['mtgo_id' => 12345, 'quantity' => 1],
    ], now()->addHour());
    Cache::put('archetype_detect:match-token-missing:version', 1, now()->addHour());

    $job = new EstimateArchetypeJob('match-token-missing', 1);
    $job->handle();

    Event::assertNotDispatched(ArchetypeEstimateUpdated::class);
    Http::assertNothingSent();
});

it('uses Unknown as player name when player key is missing from cache', function () {
    Event::fake();

    Http::fake([
        '*' => Http::response([
            ['uuid' => 'arch-uuid-2', 'confidence' => 0.80],
        ], 200),
    ]);

    Archetype::factory()->create([
        'uuid' => 'arch-uuid-2',
        'name' => 'Control',
        'color_identity' => 'UW',
    ]);

    MtgoMatch::factory()->create([
        'token' => 'match-token-5',
        'format' => 'Legacy',
    ]);

    Cache::put('archetype_detect:match-token-5:cards', [
        ['mtgo_id' => 99001, 'quantity' => 4],
    ], now()->addHour());
    Cache::put('archetype_detect:match-token-5:version', 1, now()->addHour());
    // No player key set

    $job = new EstimateArchetypeJob('match-token-5', 1);
    $job->handle();

    Event::assertDispatched(ArchetypeEstimateUpdated::class, function ($event) {
        return $event->playerName === 'Unknown';
    });
});
