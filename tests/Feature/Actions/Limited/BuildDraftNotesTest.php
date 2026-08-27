<?php

use App\Actions\Limited\Read\BuildDraftNotes;
use App\Enums\DraftState;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns null when no draft is live', function () {
    Draft::factory()->create(['state' => DraftState::Abandoned]);

    expect(BuildDraftNotes::run())->toBeNull();
});

it('describes a draft that is connecting with no pick yet', function () {
    $draft = Draft::factory()->create(['state' => DraftState::Connecting]);

    $notes = BuildDraftNotes::run();

    expect($notes)->not->toBeNull()
        ->and($notes->draftId)->toBe($draft->id)
        ->and($notes->leagueId)->toBe($draft->league_id)
        ->and($notes->state)->toBe('connecting')
        ->and($notes->currentOrdinal)->toBeNull()
        ->and($notes->picks)->toHaveCount(0);
});

it('describes the pending pick with its label, pack size, and deadline', function () {
    Carbon::setTestNow('2026-08-22 11:12:00');
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);
    DraftPick::factory()->for($draft)->create([
        'ordinal' => 3,
        'pack_number' => 1,
        'pick_number' => 3,
        'cards_available' => range(154000, 154011),
        'deadline_at' => now()->addSeconds(41),
        'picked_catalog_id' => null,
        'note' => 'lean red',
    ]);

    $pick = BuildDraftNotes::run()->picks[0];

    expect($pick->ordinal)->toBe(3)
        ->and($pick->label)->toBe('P1p3')
        ->and($pick->cardsInPack)->toBe(12)
        ->and($pick->deadlineAt)->toBe(now()->addSeconds(41)->toIso8601String())
        ->and($pick->pickedCatalogId)->toBeNull()
        ->and($pick->pickedName)->toBeNull()
        ->and($pick->note)->toBe('lean red');
});

it('returns every pick in ascending ordinal so notes can be edited retroactively', function () {
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);
    foreach ([3, 1, 2] as $ordinal) {
        DraftPick::factory()->for($draft)->create([
            'ordinal' => $ordinal,
            'pack_number' => 1,
            'pick_number' => $ordinal,
            'note' => "note {$ordinal}",
        ]);
    }

    $notes = BuildDraftNotes::run();

    expect($notes->picks)->toHaveCount(3)
        ->and(collect($notes->picks)->pluck('ordinal')->all())->toBe([1, 2, 3])
        ->and(collect($notes->picks)->pluck('note')->all())->toBe(['note 1', 'note 2', 'note 3']);
});

it('uses the highest ordinal pick as the current one', function () {
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);
    DraftPick::factory()->for($draft)->create(['ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1, 'picked_catalog_id' => 154001]);
    DraftPick::factory()->for($draft)->create(['ordinal' => 2, 'pack_number' => 1, 'pick_number' => 2, 'picked_catalog_id' => null]);

    expect(BuildDraftNotes::run()->currentOrdinal)->toBe(2);
});

it('resolves the picked card name after a commit', function () {
    Card::create(['mtgo_id' => '154042', 'oracle_id' => 'o-bilbo', 'name' => 'Bilbo, Retired Burglar', 'type' => 'Legendary Creature']);
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);
    DraftPick::factory()->for($draft)->create([
        'ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1,
        'picked_catalog_id' => 154042,
    ]);

    $pick = BuildDraftNotes::run()->picks[0];

    expect($pick->pickedCatalogId)->toBe(154042)
        ->and($pick->pickedName)->toBe('Bilbo, Retired Burglar');
});

it('falls back to the catalog id when the picked card is unknown', function () {
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);
    DraftPick::factory()->for($draft)->create([
        'ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1,
        'picked_catalog_id' => 999999,
    ]);

    expect(BuildDraftNotes::run()->picks[0]->pickedName)->toBe('#999999');
});

it('resolves every picked name in a single card query', function () {
    // The window polls once a second, so a resolve per pick would be 45
    // queries a tick by the end of pack three.
    Card::create(['mtgo_id' => '154001', 'oracle_id' => 'o-1', 'name' => 'One', 'type' => 'Creature']);
    Card::create(['mtgo_id' => '154002', 'oracle_id' => 'o-2', 'name' => 'Two', 'type' => 'Creature']);
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);
    foreach ([154001, 154002, 154003] as $index => $catalogId) {
        DraftPick::factory()->for($draft)->create([
            'ordinal' => $index + 1, 'pack_number' => 1, 'pick_number' => $index + 1,
            'picked_catalog_id' => $catalogId,
        ]);
    }

    DB::enableQueryLog();
    $notes = BuildDraftNotes::run();
    $cardQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], '"cards"'))->count();
    DB::disableQueryLog();

    expect($cardQueries)->toBe(1)
        ->and(collect($notes->picks)->pluck('pickedName')->all())->toBe(['One', 'Two', '#154003']);
});

it('still describes a finished draft inside the grace window', function () {
    Carbon::setTestNow('2026-08-22 12:40:00');
    $draft = Draft::factory()->create(['state' => DraftState::Finished, 'ended_at' => now()->subSeconds(5)]);
    DraftPick::factory()->for($draft)->create(['ordinal' => 42, 'pack_number' => 3, 'pick_number' => 14, 'picked_catalog_id' => 154001]);

    $notes = BuildDraftNotes::run();

    expect($notes->state)->toBe('finished')
        ->and($notes->currentOrdinal)->toBe(42)
        ->and($notes->picks[0]->label)->toBe('P3p14');
});

it('reports a null league for a draft that is not linked to one yet', function () {
    $draft = Draft::factory()->create(['league_id' => null, 'state' => DraftState::Picking]);
    DraftPick::factory()->for($draft)->create(['ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1]);

    $notes = BuildDraftNotes::run();

    // The window keys its save URL off leagueId, so an unlinked draft has to
    // be distinguishable from a linked one rather than defaulting to zero.
    expect($notes)->not->toBeNull()
        ->and($notes->draftId)->toBe($draft->id)
        ->and($notes->leagueId)->toBeNull()
        ->and($notes->currentOrdinal)->toBe(1);
});
