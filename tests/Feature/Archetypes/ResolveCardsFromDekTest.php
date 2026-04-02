<?php

use App\Actions\Archetypes\ResolveCardsFromDek;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Clear the global Http::fake() from Pest.php beforeEach so test-specific
// fakes take priority. Re-add a catch-all '*' in each test's fake array
// to keep NativePHP facades happy.
beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

it('resolves card data from parsed dek entries and derives colour identity', function () {
    Http::fake([
        '*/api/cards/resolve' => Http::response([
            'cards' => [
                [
                    'mtgo_id' => 12345,
                    'oracle_id' => 'oracle-bolt',
                    'name' => 'Lightning Bolt',
                    'type' => 'Instant',
                    'image' => 'https://example.com/bolt.jpg',
                    'art_crop' => 'https://example.com/bolt-crop.jpg',
                    'cmc' => 1,
                    'identity' => 'R',
                ],
                [
                    'mtgo_id' => 67890,
                    'oracle_id' => 'oracle-path',
                    'name' => 'Path to Exile',
                    'type' => 'Instant',
                    'image' => 'https://example.com/path.jpg',
                    'art_crop' => 'https://example.com/path-crop.jpg',
                    'cmc' => 1,
                    'identity' => 'W',
                ],
            ],
        ]),
    ]);

    $parsedCards = [
        ['mtgo_id' => 12345, 'quantity' => 4, 'sideboard' => false],
        ['mtgo_id' => 67890, 'quantity' => 2, 'sideboard' => true],
    ];

    $result = ResolveCardsFromDek::run($parsedCards);

    expect($result['color_identity'])->toBe('W,R');
    expect($result['cards'])->toHaveCount(2);
    expect($result['cards'][0])->toMatchArray([
        'mtgo_id' => 12345,
        'name' => 'Lightning Bolt',
        'quantity' => 4,
        'sideboard' => false,
    ]);

    expect(Card::count())->toBe(2);
    expect(Card::where('oracle_id', 'oracle-bolt')->exists())->toBeTrue();
});

it('excludes lands from colour identity derivation', function () {
    Http::fake([
        '*/api/cards/resolve' => Http::response([
            'cards' => [
                [
                    'mtgo_id' => 11111,
                    'oracle_id' => 'oracle-mountain',
                    'name' => 'Mountain',
                    'type' => 'Basic Land',
                    'image' => null,
                    'art_crop' => null,
                    'cmc' => 0,
                    'identity' => 'R',
                ],
                [
                    'mtgo_id' => 22222,
                    'oracle_id' => 'oracle-bolt',
                    'name' => 'Lightning Bolt',
                    'type' => 'Instant',
                    'image' => null,
                    'art_crop' => null,
                    'cmc' => 1,
                    'identity' => 'R',
                ],
            ],
        ]),
    ]);

    $parsedCards = [
        ['mtgo_id' => 11111, 'quantity' => 8, 'sideboard' => false],
        ['mtgo_id' => 22222, 'quantity' => 4, 'sideboard' => false],
    ];

    $result = ResolveCardsFromDek::run($parsedCards);

    expect($result['color_identity'])->toBe('R');
});

it('throws on API failure', function () {
    Http::fake([
        '*/api/cards/resolve' => Http::response([], 500),
    ]);

    ResolveCardsFromDek::run([
        ['mtgo_id' => 12345, 'quantity' => 4, 'sideboard' => false],
    ]);
})->throws(RuntimeException::class);
