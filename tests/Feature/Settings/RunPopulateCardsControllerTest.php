<?php

use App\Actions\Decks\GenerateDeckSignature;
use App\Facades\Mtgo;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Before this endpoint scanned for missing rows it only ran
 * PopulateMissingCardData, which starts from `Card::whereNull('name')` and
 * so enriches existing rows without ever creating one. On a device whose
 * history arrived by cloud sync the cards table is empty, which made the
 * only user-facing recovery path a no-op.
 */
it('creates the missing card stubs the local data already references', function () {
    $signature = GenerateDeckSignature::run(collect([
        ['mtgo_id' => 4242, 'quantity' => 4, 'sideboard' => false],
    ]));

    Card::query()->delete();

    DeckVersion::factory()->create([
        'deck_id' => Deck::factory()->create()->id,
        'signature' => $signature,
    ]);

    expect(Card::query()->count())->toBe(0);

    $this->post(route('settings.populate-cards'))->assertRedirect();

    expect(Card::query()->where('mtgo_id', 4242)->exists())->toBeTrue();
});

it('reports a failure back to the page rather than throwing', function () {
    Mtgo::partialMock()
        ->shouldReceive('populateMissingCardData')
        ->andThrow(new RuntimeException('Scryfall unreachable'));

    $this->post(route('settings.populate-cards'))
        ->assertRedirect()
        ->assertSessionHasErrors('populateCards');
});
