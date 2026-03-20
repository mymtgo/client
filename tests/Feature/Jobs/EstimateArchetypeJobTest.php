<?php

use App\Events\ArchetypeEstimateUpdated;
use App\Jobs\EstimateArchetypeJob;
use App\Models\Archetype;
use App\Models\Card;
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

    Card::factory()->create([
        'name' => 'Lightning Bolt',
        'mtgo_id' => 12345,
    ]);

    MtgoMatch::factory()->create([
        'token' => 'match-token-1',
        'format' => 'Modern',
    ]);

    $cards = [
        ['card_name' => 'Lightning Bolt', 'quantity' => 4, 'player' => 'OpponentUser'],
    ];

    Cache::put('archetype_detect:match-token-1:cards', $cards, now()->addHour());
    Cache::put('archetype_detect:match-token-1:version', 3, now()->addHour());

    $job = new EstimateArchetypeJob('match-token-1', 3);
    $job->handle();

    Event::assertDispatched(ArchetypeEstimateUpdated::class, function ($event) use ($archetype) {
        return $event->matchToken === 'match-token-1'
            && $event->playerName === 'OpponentUser'
            && $event->archetypeName === $archetype->name
            && $event->archetypeColorIdentity === 'R'
            && $event->confidence === 92
            && $event->cardsSeen === 1;
    });
});

it('exits when version is stale (superseded by newer job)', function () {
    Event::fake();

    Http::fake(['*' => Http::response('', 200)]);

    Card::factory()->create(['name' => 'Lightning Bolt', 'mtgo_id' => 12345]);
    MtgoMatch::factory()->create(['token' => 'match-token-2', 'format' => 'Modern']);

    Cache::put('archetype_detect:match-token-2:cards', [
        ['card_name' => 'Lightning Bolt', 'quantity' => 1, 'player' => 'Opp'],
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

it('skips cards not found in local DB but still works with partial list', function () {
    Event::fake();

    Http::fake([
        '*' => Http::response([
            ['uuid' => 'arch-uuid-partial', 'confidence' => 0.75],
        ], 200),
    ]);

    Archetype::factory()->create([
        'uuid' => 'arch-uuid-partial',
        'name' => 'Control',
        'color_identity' => 'UW',
    ]);

    $knownCard = Card::factory()->create([
        'name' => 'Counterspell',
        'mtgo_id' => 99001,
    ]);

    MtgoMatch::factory()->create(['token' => 'match-token-4', 'format' => 'Legacy']);

    $cards = [
        ['card_name' => 'Counterspell', 'quantity' => 4, 'player' => 'Opp'],
        ['card_name' => 'NonExistentCard9999', 'quantity' => 2, 'player' => 'Opp'],
    ];

    Cache::put('archetype_detect:match-token-4:cards', $cards, now()->addHour());
    Cache::put('archetype_detect:match-token-4:version', 1, now()->addHour());

    $job = new EstimateArchetypeJob('match-token-4', 1);
    $job->handle();

    Event::assertDispatched(ArchetypeEstimateUpdated::class, function ($event) {
        return $event->matchToken === 'match-token-4'
            && $event->confidence === 75;
    });

    Http::assertSent(function ($request) use ($knownCard) {
        $sentCards = $request->data()['cards'];
        expect($sentCards)->toHaveCount(1);
        expect((string) $sentCards[0]['mtgo_id'])->toBe((string) $knownCard->mtgo_id);

        return true;
    });
});

it('does nothing when all cards are missing from local DB', function () {
    Event::fake();

    Http::fake(['*' => Http::response('', 200)]);

    MtgoMatch::factory()->create(['token' => 'match-token-5', 'format' => 'Modern']);

    Cache::put('archetype_detect:match-token-5:cards', [
        ['card_name' => 'CardThatDoesNotExist', 'quantity' => 1, 'player' => 'Opp'],
    ], now()->addHour());
    Cache::put('archetype_detect:match-token-5:version', 1, now()->addHour());

    $job = new EstimateArchetypeJob('match-token-5', 1);
    $job->handle();

    Event::assertNotDispatched(ArchetypeEstimateUpdated::class);
    Http::assertNothingSent();
});

it('does nothing when match does not exist', function () {
    Event::fake();

    Http::fake(['*' => Http::response('', 200)]);

    Card::factory()->create(['name' => 'Lightning Bolt', 'mtgo_id' => 12345]);

    Cache::put('archetype_detect:match-token-missing:cards', [
        ['card_name' => 'Lightning Bolt', 'quantity' => 1, 'player' => 'Opp'],
    ], now()->addHour());
    Cache::put('archetype_detect:match-token-missing:version', 1, now()->addHour());

    $job = new EstimateArchetypeJob('match-token-missing', 1);
    $job->handle();

    Event::assertNotDispatched(ArchetypeEstimateUpdated::class);
    Http::assertNothingSent();
});
