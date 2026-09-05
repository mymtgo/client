<?php

use App\Models\Archetype;
use App\Models\Deck;
use App\Models\SideboardGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a guide and redirects to its editor', function () {
    $deck = Deck::factory()->create();
    $archetype = Archetype::factory()->create();

    $response = $this->post(route('decks.sideboard-guides.store', $deck), ['archetype_id' => $archetype->id]);

    $guide = SideboardGuide::sole();

    expect($guide->deck_id)->toBe($deck->id);
    expect($guide->archetype_id)->toBe($archetype->id);

    $response->assertRedirect(route('decks.sideboard-guides.edit', [$deck, $guide]));
});

it('rejects a second guide for the same deck and archetype with a clear message', function () {
    $deck = Deck::factory()->create();
    $archetype = Archetype::factory()->create();
    SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id]);

    $this->from(route('decks.sideboard-guides.index', $deck))
        ->post(route('decks.sideboard-guides.store', $deck), ['archetype_id' => $archetype->id])
        ->assertRedirect(route('decks.sideboard-guides.index', $deck))
        ->assertSessionHasErrors(['archetype_id' => 'A guide for this archetype already exists for this deck.']);

    expect(SideboardGuide::count())->toBe(1);
});

it('allows the same archetype on a different deck', function () {
    $archetype = Archetype::factory()->create();
    $first = Deck::factory()->create();
    $second = Deck::factory()->create();
    SideboardGuide::factory()->create(['deck_id' => $first->id, 'archetype_id' => $archetype->id]);

    $this->post(route('decks.sideboard-guides.store', $second), ['archetype_id' => $archetype->id])
        ->assertRedirect();

    expect(SideboardGuide::count())->toBe(2);
});

it('requires an existing archetype', function () {
    $deck = Deck::factory()->create();

    $this->post(route('decks.sideboard-guides.store', $deck), ['archetype_id' => 999])
        ->assertSessionHasErrors('archetype_id');

    $this->post(route('decks.sideboard-guides.store', $deck), [])
        ->assertSessionHasErrors('archetype_id');
});
