<?php

use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('decodes legacy oracle_id-anchored signatures', function () {
    $deck = Deck::factory()->create();
    $signature = base64_encode('oracle-1001:4:false|oracle-1002:3:true');

    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $signature,
    ]);

    expect($version->cards)->toEqual([
        ['oracle_id' => 'oracle-1001', 'quantity' => '4', 'sideboard' => 'false'],
        ['oracle_id' => 'oracle-1002', 'quantity' => '3', 'sideboard' => 'true'],
    ]);
});

it('decodes new mtgo_id-anchored signatures and resolves oracle_id', function () {
    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);
    Card::factory()->create(['mtgo_id' => 1002, 'oracle_id' => 'oracle-1002']);

    $deck = Deck::factory()->create();
    $signature = base64_encode('1001:4:false|1002:3:true');

    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $signature,
    ]);

    expect($version->cards)->toEqual([
        ['oracle_id' => 'oracle-1001', 'mtgo_id' => 1001, 'quantity' => '4', 'sideboard' => 'false'],
        ['oracle_id' => 'oracle-1002', 'mtgo_id' => 1002, 'quantity' => '3', 'sideboard' => 'true'],
    ]);
});

it('returns null oracle_id when card row missing for new-format signature', function () {
    $deck = Deck::factory()->create();
    $signature = base64_encode('9999:1:false');

    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $signature,
    ]);

    expect($version->cards)->toEqual([
        ['oracle_id' => null, 'mtgo_id' => 9999, 'quantity' => '1', 'sideboard' => 'false'],
    ]);
});
