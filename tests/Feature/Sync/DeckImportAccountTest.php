<?php

use App\Models\Account;
use App\Models\Deck;
use App\Services\Sync\Bundles\DeckBundleBuilder;
use App\Services\Sync\Bundles\DeckBundleImporter;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Builds a deck bundle, removes the local deck, and imports the bundle back
 * as a device restoring history it has never seen would.
 */
function importDeckAfresh(Deck $deck): Deck
{
    $bundle = app(DeckBundleBuilder::class)->build($deck->fresh());
    $mtgoId = $deck->mtgo_id;

    $deck->versions()->delete();
    $deck->forceDelete();

    app(DeckBundleImporter::class)->import($bundle, CanonicalJson::hash($bundle));

    return Deck::where('mtgo_id', $mtgoId)->firstOrFail();
}

it('attaches an imported deck to the active account', function () {
    $account = Account::factory()->create(['active' => true]);

    $reborn = importDeckAfresh(Deck::factory()->create(['account_id' => $account->id]));

    expect($reborn->account_id)->toBe($account->id);
});

it('leaves a deck that already belongs to another account alone', function () {
    $owner = Account::factory()->create(['active' => false]);
    $active = Account::factory()->create(['active' => true]);

    $deck = Deck::factory()->create(['account_id' => $owner->id]);
    $bundle = app(DeckBundleBuilder::class)->build($deck->fresh());

    app(DeckBundleImporter::class)->import($bundle, CanonicalJson::hash($bundle));

    expect($deck->fresh()->account_id)->toBe($owner->id)
        ->not->toBe($active->id);
});

it('imports a deck unattached when no account is known yet', function () {
    $reborn = importDeckAfresh(Deck::factory()->create(['account_id' => null]));

    expect($reborn->account_id)->toBeNull();
});
