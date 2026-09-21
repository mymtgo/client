<?php

use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runPruneImpossibleCardStubs(): void
{
    (require database_path('migrations/2026_09_21_090000_prune_impossible_card_stubs.php'))->up();
}

it('deletes unresolved stubs whose catalog id is impossible', function () {
    Card::factory()->stub()->create(['mtgo_id' => '89215000000']);
    Card::factory()->stub()->create(['mtgo_id' => '590000000']);

    runPruneImpossibleCardStubs();

    expect(Card::count())->toBe(0);
});

it('keeps an impossible id that somehow resolved, because something knows what it is', function () {
    Card::factory()->create([
        'mtgo_id' => '89215000000',
        'name' => 'Real Card',
        'scryfall_id' => 'scryfall-real',
    ]);

    runPruneImpossibleCardStubs();

    expect(Card::count())->toBe(1);
});

it('keeps unresolved stubs with real catalog ids, which may still resolve later', function () {
    Card::factory()->stub()->create(['mtgo_id' => '154069']);

    runPruneImpossibleCardStubs();

    expect(Card::where('mtgo_id', '154069')->exists())->toBeTrue();
});

it('can run twice', function () {
    Card::factory()->stub()->create(['mtgo_id' => '89215000000']);

    runPruneImpossibleCardStubs();
    runPruneImpossibleCardStubs();

    expect(Card::count())->toBe(0);
});
