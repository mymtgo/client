<?php

use App\Actions\Import\ScoreMatchConfidence;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('computes confidence as ratio of matching oracle_ids', function () {
    Card::create(['mtgo_id' => 100, 'oracle_id' => 'oracle-a', 'name' => 'Lightning Bolt']);
    Card::create(['mtgo_id' => 200, 'oracle_id' => 'oracle-b', 'name' => 'Mountain']);
    Card::create(['mtgo_id' => 300, 'oracle_id' => 'oracle-c', 'name' => 'Goblin Guide']);

    // Signature format: oracle_id:quantity:sideboard|...
    $signature = base64_encode('oracle-a:4:false|oracle-b:4:false');

    $deckVersion = DeckVersion::factory()->create([
        'signature' => $signature,
    ]);

    // Game log found mtgo_ids 100, 200, 300 — oracle-a, oracle-b, oracle-c
    $confidence = ScoreMatchConfidence::run([100, 200, 300], $deckVersion);

    // 2 out of 3 oracle_ids match the deck
    expect($confidence)->toBe(round(2 / 3, 2));
});

it('returns null when no oracle_ids can be resolved', function () {
    $signature = base64_encode('oracle-a:4:false');
    $deckVersion = DeckVersion::factory()->create([
        'signature' => $signature,
    ]);

    // mtgo_id 999 has no card record
    $confidence = ScoreMatchConfidence::run([999], $deckVersion);

    expect($confidence)->toBeNull();
});

it('returns null for empty mtgo_ids', function () {
    $deckVersion = DeckVersion::factory()->create();

    expect(ScoreMatchConfidence::run([], $deckVersion))->toBeNull();
});

it('returns 1.0 when all game log cards match the deck', function () {
    Card::create(['mtgo_id' => 100, 'oracle_id' => 'oracle-a', 'name' => 'Lightning Bolt']);
    Card::create(['mtgo_id' => 200, 'oracle_id' => 'oracle-b', 'name' => 'Mountain']);

    $signature = base64_encode('oracle-a:4:false|oracle-b:4:false');
    $deckVersion = DeckVersion::factory()->create([
        'signature' => $signature,
    ]);

    $confidence = ScoreMatchConfidence::run([100, 200], $deckVersion);

    expect($confidence)->toBe(1.0);
});
