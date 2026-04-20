<?php

use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the deck tournaments tab', function () {
    $deck = Deck::factory()->create();

    $response = $this->get("/decks/{$deck->id}/tournaments");

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->component('decks/Tournaments'));
});
