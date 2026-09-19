<?php

use App\Facades\AppSettings;
use App\Jobs\RunSyncJob;
use App\Models\Deck;
use App\Services\Sync\SyncTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    Bus::fake();
});

it('enables cloud sync for a deck, stores the slots and queues a sync run', function () {
    $deck = Deck::factory()->create(['mtgo_id' => '111']);
    Http::fake(['*/api/sync/decks/111' => Http::response(['limit' => 1, 'used' => 1, 'decks' => [
        ['client_id' => '111', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null],
    ]], 201)]);

    $this->patch(route('decks.update-cloud-sync', $deck), ['enabled' => true])->assertRedirect();

    expect((bool) $deck->fresh()->cloud_sync_enabled)->toBeTrue()
        ->and(AppSettings::syncSlots()['used'])->toBe(1);
    Bus::assertDispatched(RunSyncJob::class);
});

it('disables cloud sync without queuing a run', function () {
    $deck = Deck::factory()->create(['mtgo_id' => '111', 'cloud_sync_enabled' => true]);
    Http::fake(['*/api/sync/decks/111' => Http::response(['limit' => 1, 'used' => 1, 'decks' => [
        ['client_id' => '111', 'enabled_at' => 'x', 'disabled_at' => 'y', 'frees_at' => 'z'],
    ]])]);

    $this->patch(route('decks.update-cloud-sync', $deck), ['enabled' => false])->assertRedirect();

    expect((bool) $deck->fresh()->cloud_sync_enabled)->toBeFalse();
    Bus::assertNotDispatched(RunSyncJob::class);
});

it('reports slot_limit as a validation error and leaves the flag off', function () {
    $deck = Deck::factory()->create(['mtgo_id' => '222']);
    Http::fake(['*/api/sync/decks/222' => Http::response(['error' => 'slot_limit', 'limit' => 1, 'used' => 1, 'frees_at' => null], 422)]);

    $this->from(route('decks.settings', $deck))
        ->patch(route('decks.update-cloud-sync', $deck), ['enabled' => true])
        ->assertRedirect(route('decks.settings', $deck))
        ->assertSessionHasErrors('cloud_sync');

    expect((bool) $deck->fresh()->cloud_sync_enabled)->toBeFalse();
});

it('refuses when the device is not linked', function () {
    app(SyncTokens::class)->clear();
    $deck = Deck::factory()->create();

    $this->patch(route('decks.update-cloud-sync', $deck), ['enabled' => true])->assertStatus(409);
});

it('uses the sanitised client id for a limited deck', function () {
    $deck = Deck::factory()->create(['mtgo_id' => 'limited:abc']);
    Http::fake(['*/api/sync/decks/limited_abc' => Http::response(['limit' => 1, 'used' => 1, 'decks' => [
        ['client_id' => 'limited_abc', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null],
    ]], 201)]);

    $this->patch(route('decks.update-cloud-sync', $deck), ['enabled' => true])->assertRedirect();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/sync/decks/limited_abc'));
});
