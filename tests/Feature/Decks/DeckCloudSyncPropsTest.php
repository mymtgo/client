<?php

use App\Facades\AppSettings;
use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('tells the deck settings page when limited sync needs a supporter account', function () {
    AppSettings::setSyncSlots(['tier' => 'free', 'limit' => 1, 'used' => 0, 'decks' => []]);

    $deck = Deck::factory()->create(['mtgo_id' => 'limited:abc', 'format' => 'Limited']);

    $this->get(route('decks.settings', $deck))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('cloudSync.requiresSupporter', true));
});

it('does not gate a constructed deck, or a limited one for a supporter', function () {
    AppSettings::setSyncSlots(['tier' => 'free', 'limit' => 1, 'used' => 0, 'decks' => []]);
    $constructed = Deck::factory()->create(['mtgo_id' => '110186502', 'format' => 'Modern']);

    $this->get(route('decks.settings', $constructed))
        ->assertInertia(fn ($page) => $page->where('cloudSync.requiresSupporter', false));

    AppSettings::setSyncSlots(['tier' => 'supporter', 'limit' => null, 'used' => 0, 'decks' => []]);
    $limited = Deck::factory()->create(['mtgo_id' => 'limited:abc', 'format' => 'Limited']);

    $this->get(route('decks.settings', $limited))
        ->assertInertia(fn ($page) => $page->where('cloudSync.requiresSupporter', false));
});
