<?php

use App\Models\Card;
use App\Models\Deck;
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
