<?php

use App\Actions\Archetypes\GenerateDekFile;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates valid dek XML from highest seen_count deck', function () {
    $archetype = Archetype::factory()->create();

    $bolt = Card::factory()->create([
        'mtgo_id' => 12345,
        'name' => 'Lightning Bolt',
    ]);
    $smash = Card::factory()->create([
        'mtgo_id' => 67890,
        'name' => 'Smash to Smithereens',
    ]);

    $deck = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 10]);
    $deck->cards()->attach($bolt->id, ['quantity' => 4, 'sideboard' => false]);
    $deck->cards()->attach($smash->id, ['quantity' => 2, 'sideboard' => true]);

    $xml = GenerateDekFile::run($archetype);

    expect($xml)->toContain('<?xml version="1.0" encoding="UTF-8"?>');
    expect($xml)->toContain('<NetDeckID>0</NetDeckID>');
    expect($xml)->toContain('CatID="12345"');
    expect($xml)->toContain('Quantity="4"');
    expect($xml)->toContain('Sideboard="false"');
    expect($xml)->toContain('Name="Lightning Bolt"');
    expect($xml)->toContain('CatID="67890"');
    expect($xml)->toContain('Sideboard="true"');
    expect($xml)->toContain('Annotation="0"');
});

it('uses specified deck when archetype_deck_id provided', function () {
    $archetype = Archetype::factory()->create();

    $cardA = Card::factory()->create(['mtgo_id' => 11111, 'name' => 'Shock']);
    $cardB = Card::factory()->create(['mtgo_id' => 22222, 'name' => 'Counterspell']);

    $deckA = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 1]);
    $deckA->cards()->attach($cardA->id, ['quantity' => 4, 'sideboard' => false]);

    $deckB = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 5]);
    $deckB->cards()->attach($cardB->id, ['quantity' => 4, 'sideboard' => false]);

    $xml = GenerateDekFile::run($archetype, $deckA->id);

    expect($xml)->toContain('CatID="11111"');
    expect($xml)->toContain('Name="Shock"');
    expect($xml)->not->toContain('CatID="22222"');
});

it('falls back to highest seen_count deck when no id given', function () {
    $archetype = Archetype::factory()->create();

    $cardA = Card::factory()->create(['mtgo_id' => 11111, 'name' => 'Shock']);
    $cardB = Card::factory()->create(['mtgo_id' => 22222, 'name' => 'Counterspell']);

    $deckA = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 1]);
    $deckA->cards()->attach($cardA->id, ['quantity' => 4, 'sideboard' => false]);

    $deckB = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 5]);
    $deckB->cards()->attach($cardB->id, ['quantity' => 4, 'sideboard' => false]);

    $xml = GenerateDekFile::run($archetype);

    expect($xml)->toContain('CatID="22222"');
    expect($xml)->toContain('Name="Counterspell"');
    expect($xml)->not->toContain('CatID="11111"');
});

it('throws when archetype has no decks', function () {
    $archetype = Archetype::factory()->create();

    expect(fn () => GenerateDekFile::run($archetype))
        ->toThrow(RuntimeException::class, 'Archetype has no decklist to export.');
});
