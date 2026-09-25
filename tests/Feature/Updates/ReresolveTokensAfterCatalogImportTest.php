<?php

use App\Facades\AppSettings;
use App\Jobs\PopulateMissingCardData;
use App\Models\Card;
use App\Updates\ReresolveTokensAfterCatalogImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Drop the global Http::fake() stub from Pest.php so test-specific stubs win.
    $factory = Http::getFacadeRoot();
    (new ReflectionProperty($factory, 'stubCallbacks'))->setValue($factory, collect());

    AppSettings::setDeviceId('device-token-update-test');
    AppSettings::setApiKey('key-one');
    AppSettings::setApiKeyExpiresAt(now()->addHour()->toIso8601String());
});

it('re-resolves tokens once the API knows at least one of them', function () {
    Bus::fake();
    $token = Card::factory()->create(['mtgo_id' => '125873', 'type' => 'Token Creature', 'scryfall_id' => 'green-cat']);
    Http::fake(['*/api/cards' => Http::response([['value' => '125873', 'scryfall_id' => 'mh3-cat', 'name' => 'Cat', 'layout' => 'token']])]);

    (new ReresolveTokensAfterCatalogImport)->run();

    expect($token->fresh()->scryfall_id)->toBeNull();
    Bus::assertDispatched(PopulateMissingCardData::class);
});

it('waits for a later launch while the API knows none of them', function () {
    Bus::fake();
    $token = Card::factory()->create(['mtgo_id' => '125873', 'type' => 'Token Creature', 'scryfall_id' => 'green-cat']);
    Http::fake(['*/api/cards' => Http::response([])]);

    expect(fn () => (new ReresolveTokensAfterCatalogImport)->run())->toThrow(RuntimeException::class);

    expect($token->fresh()->scryfall_id)->toBe('green-cat');
    Bus::assertNotDispatched(PopulateMissingCardData::class);
});

it('finishes at once on an install with no tokens', function () {
    Bus::fake();
    Http::fake();

    (new ReresolveTokensAfterCatalogImport)->run();

    Http::assertNothingSent();
    Bus::assertNotDispatched(PopulateMissingCardData::class);
});
