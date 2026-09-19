<?php

use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\Deck;
use App\Services\Sync\SyncTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the deck settings page', function () {
    $deck = Deck::factory()->create();

    $this->get(route('decks.settings', $deck))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('decks/Settings'));
});

it('includes cover art when set', function () {
    $card = Card::factory()->create(['art_crop' => 'https://example.com/art.jpg']);
    $deck = Deck::factory()->create(['cover_id' => $card->id]);

    $this->get(route('decks.settings', $deck))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('decks/Settings')
            ->where('coverArt.id', $card->id)
        );
});

it('passes null cover art when not set', function () {
    $deck = Deck::factory()->create();

    $this->get(route('decks.settings', $deck))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('coverArt', null));
});

it('passes cloud sync state for the deck', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    $held = Deck::factory()->create(['mtgo_id' => '999', 'name' => 'Tron', 'cloud_sync_enabled' => true]);
    $deck = Deck::factory()->create(['mtgo_id' => '111']);
    AppSettings::setSyncSlots(['limit' => 1, 'used' => 1, 'decks' => [
        ['client_id' => '999', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null],
    ]]);

    $this->get(route('decks.settings', $deck))
        ->assertInertia(fn ($page) => $page
            ->where('cloudSync.linked', true)
            ->where('cloudSync.enabled', false)
            ->where('cloudSync.limit', 1)
            ->where('cloudSync.used', 1)
            ->where('cloudSync.freesAt', null)
            ->where('cloudSync.heldBy', 'Tron')
        );
});

it('passes linked and enabled cloud sync state', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    $deck = Deck::factory()->create(['mtgo_id' => '111', 'cloud_sync_enabled' => true]);
    AppSettings::setSyncSlots(['limit' => 1, 'used' => 1, 'decks' => [
        ['client_id' => '111', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null],
    ]]);

    $this->get(route('decks.settings', $deck))
        ->assertInertia(fn ($page) => $page
            ->where('cloudSync.linked', true)
            ->where('cloudSync.enabled', true)
            ->where('cloudSync.heldBy', null)
            ->where('cloudSync.freesAt', null)
        );
});

it('passes this deck\'s cooldown when it is cooling', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    $deck = Deck::factory()->create(['mtgo_id' => '111']);
    AppSettings::setSyncSlots(['limit' => 1, 'used' => 1, 'decks' => [
        ['client_id' => '111', 'enabled_at' => 'x', 'disabled_at' => 'y', 'frees_at' => '2026-10-10T12:00:00Z'],
    ]]);

    $this->get(route('decks.settings', $deck))
        ->assertInertia(fn ($page) => $page
            ->where('cloudSync.freesAt', '2026-10-10T12:00:00Z')
            ->where('cloudSync.heldBy', null)
        );
});

it('passes unlinked cloud sync state with no slots', function () {
    $deck = Deck::factory()->create();

    $this->get(route('decks.settings', $deck))
        ->assertInertia(fn ($page) => $page
            ->where('cloudSync.linked', false)
            ->where('cloudSync.limit', null)
            ->where('cloudSync.used', 0)
        );
});

it('lists archetypes for a deck whose format is a raw MTGO code', function () {
    $deck = Deck::factory()->create(['format' => 'CSTANDARD']);
    Archetype::factory()->create(['name' => 'Boros Aggro', 'format' => 'standard']);
    Archetype::factory()->create(['name' => 'Boros Energy', 'format' => 'modern']);

    $this->get(route('decks.settings', $deck))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('archetypes', 1)
            ->where('archetypes.0.name', 'Boros Aggro')
        );
});
