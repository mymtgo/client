<?php

use App\Facades\AppSettings;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use App\Services\Sync\SyncTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('renders the account settings page', function () {
    $this->get(route('settings.account'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Account')
            ->where('currentPage', 'account'));
});

it('lists constructed decks with their cloud sync state, synced first', function () {
    Deck::factory()->create(['name' => 'Mono Red', 'format' => 'Modern', 'mtgo_id' => '222']);
    Deck::factory()->create(['name' => 'Azorius Control', 'format' => 'Modern', 'mtgo_id' => '111', 'cloud_sync_enabled' => true]);

    $this->get(route('settings.account'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('decks', 2)
            ->where('decks.0.name', 'Azorius Control')
            ->where('decks.0.cloudSyncEnabled', true)
            ->where('decks.0.format', 'Modern')
            ->where('decks.1.name', 'Mono Red')
            ->where('decks.1.cloudSyncEnabled', false));
});

it('orders unsynced decks by last played, most recent first', function () {
    $stale = Deck::factory()->create(['name' => 'Stale', 'mtgo_id' => '1']);
    $recent = Deck::factory()->create(['name' => 'Recent', 'mtgo_id' => '2']);

    MtgoMatch::factory()->create([
        'deck_version_id' => DeckVersion::factory()->create(['deck_id' => $stale->id])->id,
        'started_at' => now()->subMonth(),
    ]);
    MtgoMatch::factory()->create([
        'deck_version_id' => DeckVersion::factory()->create(['deck_id' => $recent->id])->id,
        'started_at' => now()->subDay(),
    ]);

    $this->get(route('settings.account'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('decks.0.name', 'Recent')
            ->where('decks.1.name', 'Stale'));
});

it('leaves limited decks out of the deck sync list', function () {
    Deck::factory()->create(['name' => 'Constructed', 'mtgo_id' => '111']);
    Deck::factory()->create(['name' => 'Draft pool', 'format' => 'Limited', 'mtgo_id' => 'limited:abc']);

    $this->get(route('settings.account'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('decks', 1)
            ->where('decks.0.name', 'Constructed'));
});

it('exposes the account slot counts alongside the deck list', function () {
    AppSettings::setSyncSlots(['limit' => 1, 'used' => 1, 'decks' => []]);

    $this->get(route('settings.account'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('slots.limit', 1)
            ->where('slots.used', 1));
});

it('reports no slot limit when the account has none', function () {
    AppSettings::setSyncSlots(['limit' => null, 'used' => 3, 'decks' => []]);

    $this->get(route('settings.account'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('slots.limit', null));
});

it('carries the cooldown date of a deck whose slot is still held', function () {
    Deck::factory()->create(['name' => 'Cooling', 'mtgo_id' => '111']);
    AppSettings::setSyncSlots(['limit' => 1, 'used' => 1, 'decks' => [
        ['client_id' => '111', 'enabled_at' => '2026-09-01', 'disabled_at' => '2026-09-10', 'frees_at' => '2026-10-10'],
    ]]);

    $this->get(route('settings.account'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('decks.0.freesAt', '2026-10-10'));
});

it('leaves freesAt null for a deck with no held slot', function () {
    Deck::factory()->create(['mtgo_id' => '111']);
    AppSettings::setSyncSlots(['limit' => 1, 'used' => 0, 'decks' => []]);

    $this->get(route('settings.account'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('decks.0.freesAt', null));
});

it('reports whether this device is signed in', function () {
    $this->get(route('settings.account'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('linked', false));

    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);

    $this->get(route('settings.account'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('linked', true));
});
