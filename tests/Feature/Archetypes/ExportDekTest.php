<?php

use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates dek file and triggers save dialog', function () {
    $archetype = Archetype::factory()->withDecklist()->create();
    $card = Card::factory()->create([
        'mtgo_id' => 12345,
        'oracle_id' => 'test-oracle',
        'name' => 'Lightning Bolt',
        'type' => 'Instant',
    ]);
    $deck = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 1]);
    $deck->cards()->attach($card->id, ['quantity' => 4, 'sideboard' => false]);

    $response = $this->postJson("/archetypes/{$archetype->id}/export");

    $response->assertOk();
    $response->assertJsonStructure(['success']);
});

it('returns cancelled when dialog is dismissed', function () {
    $archetype = Archetype::factory()->withDecklist()->create();
    ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 1]);

    $response = $this->postJson("/archetypes/{$archetype->id}/export");

    $response->assertOk();
    $response->assertJson(['success' => false, 'cancelled' => true]);
});

it('exports the selected deck variant', function () {
    $archetype = Archetype::factory()->withDecklist()->create();
    $cardA = Card::factory()->create(['mtgo_id' => 11111, 'name' => 'Shock']);
    $cardB = Card::factory()->create(['mtgo_id' => 22222, 'name' => 'Counterspell']);

    $deckA = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 1]);
    $deckA->cards()->attach($cardA->id, ['quantity' => 4, 'sideboard' => false]);

    $deckB = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 5]);
    $deckB->cards()->attach($cardB->id, ['quantity' => 4, 'sideboard' => false]);

    $response = $this->postJson("/archetypes/{$archetype->id}/export", [
        'archetype_deck_id' => $deckA->id,
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['success']);
});

it('falls back to highest seen_count deck when no archetype_deck_id given', function () {
    $archetype = Archetype::factory()->withDecklist()->create();
    $cardA = Card::factory()->create(['mtgo_id' => 11111, 'name' => 'Shock']);
    $cardB = Card::factory()->create(['mtgo_id' => 22222, 'name' => 'Counterspell']);

    $deckA = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 1]);
    $deckA->cards()->attach($cardA->id, ['quantity' => 4, 'sideboard' => false]);

    $deckB = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 5]);
    $deckB->cards()->attach($cardB->id, ['quantity' => 4, 'sideboard' => false]);

    $response = $this->postJson("/archetypes/{$archetype->id}/export");

    $response->assertOk();
    $response->assertJsonStructure(['success']);
});
