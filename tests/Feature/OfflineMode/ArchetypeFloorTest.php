<?php

use App\Actions\DetermineDeckArchetype;
use App\Facades\AppSettings;
use App\Jobs\DetermineMatchArchetypesJob;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Drop the global Http::fake() stub from Pest.php so test-specific stubs
    // (and Http::preventStrayRequests()) actually take effect.
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());

    $archetype = Archetype::factory()->create([
        'format' => 'modern',
        'is_fallback' => false,
    ]);

    $deck = ArchetypeDeck::factory()->create(['archetype_id' => $archetype->id]);

    // Ten distinct non-land cards in the reference decklist.
    $this->deckCards = Card::factory()->count(10)->create([
        'type' => 'Creature',
    ])->each(fn (Card $card) => $card->update(['oracle_id' => (string) Str::uuid()]));

    foreach ($this->deckCards as $card) {
        $deck->cards()->attach($card->id, ['quantity' => 4, 'sideboard' => false]);
    }

    $this->archetype = $archetype;
    $this->deck = $deck;

    // Feed only two of them: matchedDistinct = 2, below MIN_CONFIDENT_MATCHES.
    $this->thinInput = $this->deckCards->take(2)->map(fn (Card $card) => [
        'mtgo_id' => $card->mtgo_id,
        'quantity' => 4,
    ]);
});

it('returns a sub-threshold local estimate while offline instead of nothing', function () {
    AppSettings::setOffline(true);
    Http::preventStrayRequests();

    $result = DetermineDeckArchetype::run($this->thinInput, 'modern');

    expect($result)->not->toBeNull()
        ->and($result['confidence'])->toBeLessThan(0.8);
});

it('never calls the estimate endpoint while offline', function () {
    AppSettings::setOffline(true);
    Http::preventStrayRequests();

    DetermineDeckArchetype::run($this->thinInput, 'modern');

    Http::assertNothingSent();
});

it('still defers to the api below the threshold while online', function () {
    AppSettings::setOffline(false);

    Http::fake([
        '*/api/archetypes/estimate' => Http::response([], 200),
    ]);

    DetermineDeckArchetype::run($this->thinInput, 'modern');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/archetypes/estimate'));
});

it('returns null offline when there are no candidate decks at all', function () {
    AppSettings::setOffline(true);
    Http::preventStrayRequests();

    $result = DetermineDeckArchetype::run(collect([['mtgo_id' => 999999, 'quantity' => 4]]), 'legacy');

    expect($result)->toBeNull();
});

it('estimateViaApi returns null offline without sending a request', function () {
    // estimateViaApi() is public and has a caller (ResolveOverlayOpponent)
    // that bypasses run() entirely, so this guard is verified directly
    // against the method itself, not only through run()'s two guards.
    AppSettings::setOffline(true);
    Http::preventStrayRequests();

    $result = DetermineDeckArchetype::estimateViaApi($this->thinInput, 'modern');

    expect($result)->toBeNull();
    Http::assertNothingSent();
});

it('estimateViaApi still calls the endpoint when online', function () {
    AppSettings::setOffline(false);

    Http::fake([
        '*/api/archetypes/estimate' => Http::response([], 200),
    ]);

    DetermineDeckArchetype::estimateViaApi($this->thinInput, 'modern');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/archetypes/estimate'));
});

it('runs the queued job to completion offline without reaching the estimate endpoint', function () {
    // Exercises the real-world worst case named in the offline-mode design: a
    // sub-threshold local estimate reaching DetermineMatchArchetypesJob via the
    // full match → DetermineMatchArchetypes → DetermineDeckArchetype chain.
    // Before this change the fallthrough to estimateViaApi() would throw
    // OfflineModeException from inside the job, and ShouldBeUnique retries
    // (tries: 3, backoff: [2, 5]) would fire on every attempt since the
    // fixture is deterministic. Asserting handle() completes without
    // throwing proves the job cannot be caught in that retry loop.
    AppSettings::setOffline(true);
    Http::preventStrayRequests();

    $match = MtgoMatch::factory()->create([
        'format' => 'modern',
        'archetype_detection_queued_at' => now(),
    ]);
    $game = Game::factory()->create(['match_id' => $match->id]);
    $local = Player::factory()->create();

    $game->players()->attach($local->id, [
        'instance_id' => 1,
        'is_local' => true,
        'on_play' => true,
        'starting_hand_size' => 7,
        'deck_json' => $this->thinInput->values()->all(),
    ]);

    (new DetermineMatchArchetypesJob($match->id))->handle();

    Http::assertNothingSent();
    expect($match->fresh()->archetype_detection_queued_at)->toBeNull();
});
