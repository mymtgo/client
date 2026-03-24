<?php

use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns empty array when signature is empty string', function () {
    $version = DeckVersion::factory()->create(['signature' => '']);

    expect($version->cards)->toBe([]);
});

it('returns empty array when signature is invalid base64', function () {
    $version = DeckVersion::factory()->create(['signature' => '!!!not-base64!!!']);

    expect($version->cards)->toBe([]);
});

it('skips malformed card entries missing required parts', function () {
    // Valid entry: oracle_id:quantity:sideboard
    // Malformed: only has one part
    $mixed = base64_encode('abc-123:4:false|malformed|def-456:2:true');
    $version = DeckVersion::factory()->create(['signature' => $mixed]);

    $cards = $version->cards;

    expect($cards)->toHaveCount(2);
    expect($cards[0]['oracle_id'])->toBe('abc-123');
    expect($cards[1]['oracle_id'])->toBe('def-456');
});

it('parses valid signatures correctly', function () {
    $signature = base64_encode('abc-123:4:false|def-456:2:true');
    $version = DeckVersion::factory()->create(['signature' => $signature]);

    $cards = $version->cards;

    expect($cards)->toHaveCount(2);
    expect($cards[0])->toBe(['oracle_id' => 'abc-123', 'quantity' => '4', 'sideboard' => 'false']);
    expect($cards[1])->toBe(['oracle_id' => 'def-456', 'quantity' => '2', 'sideboard' => 'true']);
});
