<?php

use App\Actions\Decks\GenerateDeckSignature;
use App\Jobs\RegenerateDeckSignaturesJob;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rewrites legacy oracle_id-anchored signatures to canonical mtgo_id form', function () {
    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);
    Card::factory()->create(['mtgo_id' => 1002, 'oracle_id' => 'oracle-1002']);

    $deck = Deck::factory()->create();
    $legacy = base64_encode('oracle-1001:4:false|oracle-1002:3:true');

    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $legacy,
    ]);

    (new RegenerateDeckSignaturesJob)->handle();

    $expected = GenerateDeckSignature::run(collect([
        ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => 'false'],
        ['mtgo_id' => 1002, 'quantity' => 3, 'sideboard' => 'true'],
    ]));

    expect($version->fresh()->signature)->toBe($expected);
});

it('is idempotent on already-canonical signatures', function () {
    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);

    $deck = Deck::factory()->create();
    $canonical = GenerateDeckSignature::run(collect([
        ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => 'false'],
    ]));

    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $canonical,
    ]);

    $updatedAt = $version->updated_at;

    (new RegenerateDeckSignaturesJob)->handle();

    $fresh = $version->fresh();
    expect($fresh->signature)->toBe($canonical);
    expect($fresh->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('skips rows whose cards cannot all be resolved', function () {
    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);
    // No Card row for oracle-9999 — translation will fail for this row.

    $deck = Deck::factory()->create();
    $unresolvable = base64_encode('oracle-9999:4:false');

    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $unresolvable,
    ]);

    (new RegenerateDeckSignaturesJob)->handle();

    expect($version->fresh()->signature)->toBe($unresolvable);
});
