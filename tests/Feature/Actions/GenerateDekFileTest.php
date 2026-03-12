<?php

use App\Actions\Archetypes\GenerateDekFile;
use App\Models\Archetype;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates valid dek XML', function () {
    $archetype = Archetype::factory()->create();

    $bolt = Card::factory()->create([
        'mtgo_id' => 12345,
        'name' => 'Lightning Bolt',
    ]);
    $smash = Card::factory()->create([
        'mtgo_id' => 67890,
        'name' => 'Smash to Smithereens',
    ]);

    $archetype->cards()->attach($bolt->id, ['quantity' => 4, 'sideboard' => false]);
    $archetype->cards()->attach($smash->id, ['quantity' => 2, 'sideboard' => true]);

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

it('returns empty deck when no cards', function () {
    $archetype = Archetype::factory()->create();

    $xml = GenerateDekFile::run($archetype);

    expect($xml)->toContain('<Deck');
    expect($xml)->not->toContain('CatID');
});
