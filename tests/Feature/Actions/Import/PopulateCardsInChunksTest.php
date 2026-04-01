<?php

use App\Actions\Import\PopulateCardsInChunks;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Facades\Settings;

uses(RefreshDatabase::class);

// Clear the global Http::fake() from Pest.php beforeEach so test-specific
// fakes take priority.
beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

it('populates card data from the API', function () {
    Card::create(['mtgo_id' => 100]);

    Settings::set('device_id', 'test-device');
    Settings::set('api_key', encrypt('test-key'));

    Http::fake([
        '*/api/cards' => Http::response([
            [
                'value' => 100,
                'name' => 'Lightning Bolt',
                'oracle_id' => 'oracle-bolt',
                'scryfall_id' => 'scry-bolt',
                'type' => 'Instant',
                'sub_type' => null,
                'rarity' => 'Common',
                'color_identity' => 'R',
                'image' => 'https://example.com/bolt.jpg',
            ],
        ]),
        '*' => Http::response([], 200),
    ]);

    PopulateCardsInChunks::run();

    $card = Card::where('mtgo_id', 100)->first();
    expect($card->name)->toBe('Lightning Bolt')
        ->and($card->oracle_id)->toBe('oracle-bolt');
});

it('skips when no unpopulated cards exist', function () {
    Http::fake();

    PopulateCardsInChunks::run();

    Http::assertNothingSent();
});
