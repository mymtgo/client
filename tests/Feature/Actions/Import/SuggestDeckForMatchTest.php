<?php

use App\Actions\Import\SuggestDeckForMatch;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('suggests a deck version when oracle_ids overlap sufficiently', function () {
    $cards = collect([
        Card::factory()->create(['mtgo_id' => '100', 'oracle_id' => 'oracle-a', 'name' => 'Card A']),
        Card::factory()->create(['mtgo_id' => '200', 'oracle_id' => 'oracle-b', 'name' => 'Card B']),
        Card::factory()->create(['mtgo_id' => '300', 'oracle_id' => 'oracle-c', 'name' => 'Card C']),
    ]);

    $deck = Deck::factory()->create(['name' => 'Test Deck']);
    $signature = base64_encode('oracle-a:4:false|oracle-b:4:false|oracle-c:4:false|oracle-d:4:false');
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $signature,
    ]);

    $localCards = [
        ['mtgo_id' => 100, 'name' => 'Card A'],
        ['mtgo_id' => 200, 'name' => 'Card B'],
        ['mtgo_id' => 300, 'name' => 'Card C'],
    ];

    $result = SuggestDeckForMatch::run($localCards);

    expect($result)->not->toBeNull();
    expect($result['deck_version_id'])->toBe($version->id);
    expect($result['deck_name'])->toBe('Test Deck');
    expect($result['confidence'])->toBeGreaterThanOrEqual(0.6);
});

it('returns null when no deck matches above threshold', function () {
    Card::factory()->create(['mtgo_id' => '100', 'oracle_id' => 'oracle-x', 'name' => 'Unrelated Card']);

    $deck = Deck::factory()->create(['name' => 'Other Deck']);
    $signature = base64_encode('oracle-z:4:false|oracle-y:4:false');
    DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $signature,
    ]);

    $localCards = [
        ['mtgo_id' => 100, 'name' => 'Unrelated Card'],
    ];

    $result = SuggestDeckForMatch::run($localCards);

    expect($result)->toBeNull();
});

it('includes soft-deleted decks in matching', function () {
    $card = Card::factory()->create(['mtgo_id' => '500', 'oracle_id' => 'oracle-del', 'name' => 'Del Card']);

    $deck = Deck::factory()->create(['name' => 'Deleted Deck']);
    $deck->delete();

    $signature = base64_encode('oracle-del:4:false');
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $signature,
    ]);

    $localCards = [
        ['mtgo_id' => 500, 'name' => 'Del Card'],
    ];

    $result = SuggestDeckForMatch::run($localCards);

    expect($result)->not->toBeNull();
    expect($result['deck_deleted'])->toBeTrue();
});
