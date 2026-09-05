<?php

use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use App\Models\SideboardGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores a note against the guide deck and archetype', function () {
    $deck = Deck::factory()->create();
    $guide = SideboardGuide::factory()->create(['deck_id' => $deck->id]);

    $this->post(route('decks.sideboard-guides.notes.store', [$deck, $guide]), ['body' => 'Mull aggressively'])
        ->assertRedirect();

    $note = DeckArchetypeNote::sole();

    expect($note->deck_id)->toBe($deck->id);
    expect($note->archetype_id)->toBe($guide->archetype_id);
    expect($note->body)->toBe('Mull aggressively');
});

it('requires a body under two thousand characters', function () {
    $deck = Deck::factory()->create();
    $guide = SideboardGuide::factory()->create(['deck_id' => $deck->id]);

    $this->post(route('decks.sideboard-guides.notes.store', [$deck, $guide]), ['body' => ''])
        ->assertSessionHasErrors('body');

    $this->post(route('decks.sideboard-guides.notes.store', [$deck, $guide]), ['body' => str_repeat('a', 2001)])
        ->assertSessionHasErrors('body');
});
