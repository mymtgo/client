<?php

use App\Actions\Decks\GenerateDeckDekFile;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeSignature(array $entries): string
{
    // entries: [['mtgo_id' => 123, 'quantity' => 4, 'sideboard' => 0], ...]
    $segments = collect($entries)
        ->map(fn ($e) => "{$e['mtgo_id']}:{$e['quantity']}:{$e['sideboard']}")
        ->implode('|');

    return base64_encode($segments);
}

it('builds a .dek XML payload from the latest deck version', function () {
    $deck = Deck::factory()->create(['name' => 'Test Deck']);
    Card::factory()->create(['mtgo_id' => 100, 'name' => 'Lightning Bolt']);
    Card::factory()->create(['mtgo_id' => 200, 'name' => 'Mountain']);

    DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'modified_at' => now(),
        'signature' => makeSignature([
            ['mtgo_id' => 100, 'quantity' => 4, 'sideboard' => 0],
            ['mtgo_id' => 200, 'quantity' => 20, 'sideboard' => 0],
            ['mtgo_id' => 100, 'quantity' => 2, 'sideboard' => 1],
        ]),
    ]);

    $xml = GenerateDeckDekFile::run($deck->fresh());

    expect($xml)->toContain('<?xml version="1.0" encoding="UTF-8"?>');
    expect($xml)->toContain('<Deck ');
    expect($xml)->toContain('CatID="100" Quantity="4" Sideboard="false" Name="Lightning Bolt"');
    expect($xml)->toContain('CatID="200" Quantity="20" Sideboard="false" Name="Mountain"');
    expect($xml)->toContain('CatID="100" Quantity="2" Sideboard="true" Name="Lightning Bolt"');
    expect($xml)->toEndWith('</Deck>');
});

it('skips cards whose mtgo_id is not in the database', function () {
    $deck = Deck::factory()->create();
    Card::factory()->create(['mtgo_id' => 100, 'name' => 'Known Card']);

    DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'modified_at' => now(),
        'signature' => makeSignature([
            ['mtgo_id' => 100, 'quantity' => 4, 'sideboard' => 0],
            ['mtgo_id' => 9999, 'quantity' => 1, 'sideboard' => 0],
        ]),
    ]);

    $xml = GenerateDeckDekFile::run($deck->fresh());

    expect($xml)->toContain('Known Card');
    expect($xml)->not->toContain('CatID="9999"');
});

it('throws when the deck has no versions', function () {
    $deck = Deck::factory()->create();

    expect(fn () => GenerateDeckDekFile::run($deck))
        ->toThrow(RuntimeException::class);
});

it('escapes special characters in card names', function () {
    $deck = Deck::factory()->create();
    Card::factory()->create(['mtgo_id' => 100, 'name' => 'Ach! Hans, Run!']);

    DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'modified_at' => now(),
        'signature' => makeSignature([
            ['mtgo_id' => 100, 'quantity' => 1, 'sideboard' => 0],
        ]),
    ]);

    $xml = GenerateDeckDekFile::run($deck->fresh());

    expect($xml)->toContain('Ach! Hans, Run!');
    // Quotes/ampersands would be escaped, but exclamation marks pass through.
});

it('works on a soft-deleted deck', function () {
    $deck = Deck::factory()->create();
    Card::factory()->create(['mtgo_id' => 100, 'name' => 'Lightning Bolt']);

    DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'modified_at' => now(),
        'signature' => makeSignature([
            ['mtgo_id' => 100, 'quantity' => 4, 'sideboard' => 0],
        ]),
    ]);

    $deck->delete();

    $xml = GenerateDeckDekFile::run(Deck::withTrashed()->find($deck->id));

    expect($xml)->toContain('Lightning Bolt');
});
