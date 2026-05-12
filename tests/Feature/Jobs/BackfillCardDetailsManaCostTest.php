<?php

use App\Facades\AppSettings;
use App\Jobs\BackfillCardDetails;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Reset the global Http::fake() stub from Pest.php so test-specific fakes take priority.
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    AppSettings::setDeviceId('test-device');
    AppSettings::setApiKey('test-key');
});

it('stores mana_cost from the API response', function (): void {
    $card = Card::factory()->create([
        'mtgo_id' => '12345',
        'name' => 'Lightning Bolt',
        'art_crop' => null,
        'mana_cost' => null,
        'rarity' => 'common',
    ]);

    Http::fake([
        '*/api/cards' => Http::response([
            [
                'value' => '12345',
                'scryfall_id' => 'sf-1',
                'oracle_id' => 'oracle-1',
                'name' => 'Lightning Bolt',
                'type' => 'Instant',
                'sub_type' => null,
                'rarity' => 'common',
                'color_identity' => 'R',
                'colors' => 'R',
                'cmc' => 1,
                'mana_cost' => '{R}',
                'set_name' => 'Alpha',
                'set' => 'LEA',
                'art_crop' => 'http://example.com/art.jpg',
                'image' => 'http://example.com/img.jpg',
            ],
        ], 200),
    ]);

    (new BackfillCardDetails)->handle();

    expect($card->fresh()->mana_cost)->toBe('{R}');
});
