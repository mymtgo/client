<?php

use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('searches cards by name, one row per oracle, newest printing', function () {
    Card::factory()->create(['mtgo_id' => 100, 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);
    Card::factory()->create(['mtgo_id' => 101, 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);
    Card::factory()->create(['mtgo_id' => 102, 'oracle_id' => 'o-helix', 'name' => 'Lightning Helix', 'type' => 'Instant']);
    Card::factory()->create(['mtgo_id' => 103, 'oracle_id' => 'o-goyf', 'name' => 'Tarmogoyf']);

    $response = $this->getJson('/cards/search?q=lightning')->assertOk();

    expect($response->json())->toHaveCount(2)
        ->and($response->json('0'))->toMatchArray(['mtgoId' => 101, 'name' => 'Lightning Bolt', 'oracleId' => 'o-bolt'])
        ->and($response->json('1.name'))->toBe('Lightning Helix');
});

it('caps results at twenty and requires two characters', function () {
    foreach (range(1, 25) as $i) {
        Card::factory()->create(['mtgo_id' => 1000 + $i, 'oracle_id' => "o-{$i}", 'name' => "Island {$i}"]);
    }

    expect($this->getJson('/cards/search?q=island')->assertOk()->json())->toHaveCount(20);
    $this->getJson('/cards/search?q=i')->assertUnprocessable();
});
