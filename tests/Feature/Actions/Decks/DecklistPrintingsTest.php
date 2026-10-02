<?php

use App\Actions\Decks\BuildDecklist;
use App\Actions\Decks\EncodeDeckScreenshotData;
use App\Models\Card;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Three printings of one card in the catalog. The deck registers the one
 * inserted in the middle, so neither a first-match nor a last-match lookup
 * by oracle id lands on it by luck.
 *
 * @return array{0: DeckVersion, 1: Card}
 */
function deckWithReprintedCard(): array
{
    Storage::fake('cards');

    $oracleId = 'solitude-oracle';
    $printings = [];
    foreach ([91203, 127507, 107231] as $mtgoId) {
        Storage::disk('cards')->put("{$mtgoId}.jpg", "art-{$mtgoId}");
        $printings[$mtgoId] = Card::factory()->create([
            'mtgo_id' => $mtgoId,
            'oracle_id' => $oracleId,
            'name' => 'Solitude',
            'type' => 'Creature',
            'local_image' => "{$mtgoId}.jpg",
        ]);
    }

    $version = DeckVersion::factory()->create(['signature' => base64_encode('127507:4:false')]);

    return [$version, $printings[127507]];
}

it('shows the printing the deck registered on the decklist', function () {
    [$version, $registered] = deckWithReprintedCard();

    [$maindeck] = BuildDecklist::run($version);

    expect($maindeck['Creature']->first()->mtgoId)->toBe((int) $registered->mtgo_id);
});

it('keeps two printings of one card apart on the decklist', function () {
    [, $registered] = deckWithReprintedCard();
    $version = DeckVersion::factory()->create(['signature' => base64_encode('91203:2:false|127507:2:false')]);

    [$maindeck] = BuildDecklist::run($version);

    expect($maindeck['Creature']->pluck('mtgoId')->sort()->values()->all())->toBe([91203, 127507]);
});

it('falls back to any printing when the registered one is not in the catalog', function () {
    deckWithReprintedCard();
    // A version saved in the old oracle-id signature format carries no printing.
    $version = DeckVersion::factory()->create(['signature' => base64_encode('solitude-oracle:4:false')]);

    [$maindeck] = BuildDecklist::run($version);

    expect($maindeck['Creature']->first()->name)->toBe('Solitude');
});

it('uses the registered printing in the deck screenshot', function () {
    [$version] = deckWithReprintedCard();

    $entry = EncodeDeckScreenshotData::run($version)['nonLandCards'][0];

    expect($entry['imageBase64'])->toBe('data:image/jpeg;base64,'.base64_encode('art-127507'));
});
