<?php

use App\Actions\Decks\GenerateDeckSignature;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates consistent signature for same cards', function () {
    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);
    Card::factory()->create(['mtgo_id' => 1002, 'oracle_id' => 'oracle-1002']);

    $cards = collect([
        ['mtgo_id' => '1001', 'quantity' => '4', 'sideboard' => 'false'],
        ['mtgo_id' => '1002', 'quantity' => '4', 'sideboard' => 'false'],
    ]);

    $signature1 = GenerateDeckSignature::run($cards);
    $signature2 = GenerateDeckSignature::run($cards);

    expect($signature1)->toBe($signature2);
});

it('generates different signature for different quantities', function () {
    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);

    $cards1 = collect([
        ['mtgo_id' => '1001', 'quantity' => '4', 'sideboard' => 'false'],
    ]);

    $cards2 = collect([
        ['mtgo_id' => '1001', 'quantity' => '3', 'sideboard' => 'false'],
    ]);

    $signature1 = GenerateDeckSignature::run($cards1);
    $signature2 = GenerateDeckSignature::run($cards2);

    expect($signature1)->not->toBe($signature2);
});

it('generates different signature when sideboard differs', function () {
    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);

    $cards1 = collect([
        ['mtgo_id' => '1001', 'quantity' => '4', 'sideboard' => 'false'],
    ]);

    $cards2 = collect([
        ['mtgo_id' => '1001', 'quantity' => '4', 'sideboard' => 'true'],
    ]);

    $signature1 = GenerateDeckSignature::run($cards1);
    $signature2 = GenerateDeckSignature::run($cards2);

    expect($signature1)->not->toBe($signature2);
});

it('handles empty card list', function () {
    $cards = collect([]);

    $signature = GenerateDeckSignature::run($cards);

    expect($signature)->toBeString();
});
