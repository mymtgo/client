<?php

use App\Actions\Cards\ReresolveTokenPrintings;
use App\Jobs\PopulateMissingCardData;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('clears the printing on every token row, whatever its rarity, and nothing else', function () {
    Bus::fake();
    $marked = Card::factory()->create(['mtgo_id' => '125873', 'type' => 'Token Creature', 'rarity' => 'token', 'scryfall_id' => 'green-cat']);
    $unmarked = Card::factory()->create(['mtgo_id' => '125875', 'type' => 'Token Creature', 'rarity' => 'common', 'scryfall_id' => 'wrong-cat-warrior']);
    $card = Card::factory()->create(['mtgo_id' => '1234', 'type' => 'Legendary Creature', 'rarity' => 'mythic', 'scryfall_id' => 'ragavan']);

    expect(ReresolveTokenPrintings::run())->toBe(2)
        ->and($marked->fresh()->scryfall_id)->toBeNull()
        ->and($unmarked->fresh()->scryfall_id)->toBeNull()
        ->and($card->fresh()->scryfall_id)->toBe('ragavan');

    Bus::assertDispatched(PopulateMissingCardData::class);
});
