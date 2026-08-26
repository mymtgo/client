<?php

use App\Actions\Limited\Read\BuildDraftNotes;
use App\Enums\DraftState;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

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
        ->and($notes->ordinal)->toBeNull()
        ->and($notes->label)->toBeNull()
        ->and($notes->cardsInPack)->toBeNull()
        ->and($notes->deadlineAt)->toBeNull()
        ->and($notes->pickedCatalogId)->toBeNull()
        ->and($notes->pickedName)->toBeNull()
        ->and($notes->note)->toBeNull();
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

    $notes = BuildDraftNotes::run();

    expect($notes->ordinal)->toBe(3)
        ->and($notes->label)->toBe('P1p3')
        ->and($notes->cardsInPack)->toBe(12)
        ->and($notes->deadlineAt)->toBe(now()->addSeconds(41)->toIso8601String())
        ->and($notes->pickedCatalogId)->toBeNull()
        ->and($notes->pickedName)->toBeNull()
        ->and($notes->note)->toBe('lean red');
});

it('uses the highest ordinal pick as the current one', function () {
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);
    DraftPick::factory()->for($draft)->create(['ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1, 'picked_catalog_id' => 154001]);
    DraftPick::factory()->for($draft)->create(['ordinal' => 2, 'pack_number' => 1, 'pick_number' => 2, 'picked_catalog_id' => null]);

    expect(BuildDraftNotes::run()->ordinal)->toBe(2);
});

it('resolves the picked card name after a commit', function () {
    Card::create(['mtgo_id' => '154042', 'oracle_id' => 'o-bilbo', 'name' => 'Bilbo, Retired Burglar', 'type' => 'Legendary Creature']);
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);
    DraftPick::factory()->for($draft)->create([
        'ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1,
        'picked_catalog_id' => 154042,
    ]);

    $notes = BuildDraftNotes::run();

    expect($notes->pickedCatalogId)->toBe(154042)
        ->and($notes->pickedName)->toBe('Bilbo, Retired Burglar');
});

it('falls back to the catalog id when the picked card is unknown', function () {
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);
    DraftPick::factory()->for($draft)->create([
        'ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1,
        'picked_catalog_id' => 999999,
    ]);

    expect(BuildDraftNotes::run()->pickedName)->toBe('#999999');
});

it('still describes a finished draft inside the grace window', function () {
    Carbon::setTestNow('2026-08-22 12:40:00');
    $draft = Draft::factory()->create(['state' => DraftState::Finished, 'ended_at' => now()->subSeconds(5)]);
    DraftPick::factory()->for($draft)->create(['ordinal' => 42, 'pack_number' => 3, 'pick_number' => 14, 'picked_catalog_id' => 154001]);

    $notes = BuildDraftNotes::run();

    expect($notes->state)->toBe('finished')
        ->and($notes->ordinal)->toBe(42)
        ->and($notes->label)->toBe('P3p14');
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
        ->and($notes->ordinal)->toBe(1);
});
