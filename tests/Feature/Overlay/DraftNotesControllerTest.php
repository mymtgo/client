<?php

use App\Enums\DraftState;
use App\Models\Draft;
use App\Models\DraftPick;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('renders the window with no draft', function () {
    Carbon::setTestNow('2026-08-22 11:12:00');

    $this->get(route('overlay.draft-notes'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('overlay/DraftNotes')
        ->where('notes', null)
        ->where('serverNow', '2026-08-22T11:12:00+00:00'));
});

it('renders the current pick for a live draft', function () {
    Carbon::setTestNow('2026-08-22 11:12:00');
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);
    DraftPick::factory()->for($draft)->create([
        'ordinal' => 3, 'pack_number' => 1, 'pick_number' => 3,
        'cards_available' => range(154000, 154011),
        'deadline_at' => now()->addSeconds(41),
        'note' => null,
    ]);

    $this->get(route('overlay.draft-notes'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('overlay/DraftNotes')
        ->where('notes.draftId', $draft->id)
        ->where('notes.leagueId', $draft->league_id)
        ->where('notes.state', 'picking')
        ->where('notes.ordinal', 3)
        ->where('notes.label', 'P1p3')
        ->where('notes.cardsInPack', 12)
        ->where('notes.deadlineAt', now()->addSeconds(41)->toIso8601String())
        ->where('notes.pickedName', null)
        ->where('notes.note', null));
});

it('answers a partial reload of notes only', function () {
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);
    DraftPick::factory()->for($draft)->create(['ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1]);

    $response = inertiaPartial(route('overlay.draft-notes'), 'overlay/DraftNotes', ['notes']);

    $response->assertOk();
    expect($response->json('props.notes.ordinal'))->toBe(1)
        ->and($response->json('props'))->not->toHaveKey('serverNow');
});
