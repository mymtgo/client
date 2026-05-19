<?php

use App\Actions\Reports\GetReportSideboardOracles;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Build an old-format signature: oracle_id:quantity:sideboard|...
 *
 * @param  array<int, array{oracle_id: string, quantity: int, sideboard: string|bool|int}>  $entries
 */
function makeSideboardTestSignature(array $entries): string
{
    $segments = collect($entries)
        ->map(fn ($e) => "{$e['oracle_id']}:{$e['quantity']}:{$e['sideboard']}")
        ->implode('|');

    return base64_encode($segments);
}

it('returns union of sideboard oracle ids across given versions', function () {
    $deckA = Deck::factory()->create();
    $versionA = DeckVersion::factory()->create([
        'deck_id' => $deckA->id,
        'signature' => makeSideboardTestSignature([
            ['oracle_id' => 'oracle-main', 'quantity' => 4, 'sideboard' => 'false'],
            ['oracle_id' => 'oracle-sb-a', 'quantity' => 2, 'sideboard' => 'true'],
        ]),
    ]);

    $deckB = Deck::factory()->create();
    $versionB = DeckVersion::factory()->create([
        'deck_id' => $deckB->id,
        'signature' => makeSideboardTestSignature([
            ['oracle_id' => 'oracle-main', 'quantity' => 4, 'sideboard' => 'false'],
            ['oracle_id' => 'oracle-sb-b', 'quantity' => 3, 'sideboard' => 'true'],
        ]),
    ]);

    $result = GetReportSideboardOracles::run([$versionA->id, $versionB->id]);

    expect($result->keys()->all())->toEqualCanonicalizing(['oracle-sb-a', 'oracle-sb-b']);
});

it('returns empty collection when given no versions', function () {
    expect(GetReportSideboardOracles::run([])->all())->toBe([]);
});

it('returns empty collection when versions have no sideboard cards', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => makeSideboardTestSignature([
            ['oracle_id' => 'oracle-main', 'quantity' => 4, 'sideboard' => 'false'],
        ]),
    ]);

    expect(GetReportSideboardOracles::run([$version->id])->all())->toBe([]);
});

it('deduplicates oracle ids that are sideboard across multiple versions', function () {
    $deck = Deck::factory()->create();
    $versionA = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => makeSideboardTestSignature([
            ['oracle_id' => 'oracle-sb', 'quantity' => 2, 'sideboard' => 'true'],
        ]),
    ]);
    $versionB = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => makeSideboardTestSignature([
            ['oracle_id' => 'oracle-sb', 'quantity' => 2, 'sideboard' => 'true'],
        ]),
    ]);

    $result = GetReportSideboardOracles::run([$versionA->id, $versionB->id]);

    expect($result->keys()->all())->toBe(['oracle-sb']);
});
