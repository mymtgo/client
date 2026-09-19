<?php

use App\Actions\Sync\SetDeckCloudSync;
use App\Exceptions\Sync\LimitedRequiresSupporterException;
use App\Models\Deck;
use App\Services\Sync\SyncTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// The Feature test suite's global beforeEach registers a blanket Http::fake()
// that matches every URL and wins over any later Http::fake([...]) pattern
// (Laravel evaluates fakes in registration order, first match wins). Reset
// stubCallbacks here so each test's own fake is the only one in play, the
// same reset used elsewhere (SyncApiTest, DeviceLinkTest).
beforeEach(function () {
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());

    // The queue connection is sync in tests, so enabling sync would
    // otherwise run RunSyncJob for real, firing extra HTTP calls that
    // don't carry a `kind` and would make Http::assertSent's closure throw.
    Bus::fake();
});

it('sends the deck kind when toggling cloud sync', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);

    Http::fake(['*/api/sync/decks/*' => Http::response(['tier' => 'supporter', 'limit' => null, 'used' => 1, 'decks' => []])]);

    $deck = Deck::factory()->create(['mtgo_id' => 'limited:abc', 'format' => 'Limited']);

    SetDeckCloudSync::run($deck, true);

    Http::assertSent(fn ($request) => $request['kind'] === 'limited');
});

it('turns the server tier refusal into a typed exception', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);

    Http::fake(['*/api/sync/decks/*' => Http::response(['error' => 'limited_requires_supporter'], 422)]);

    $deck = Deck::factory()->create(['mtgo_id' => 'limited:abc', 'format' => 'Limited']);

    expect(fn () => SetDeckCloudSync::run($deck, true))
        ->toThrow(LimitedRequiresSupporterException::class);
});
