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
        ->where('notes.currentOrdinal', 3)
        ->has('notes.picks', 1)
        ->where('notes.picks.0.ordinal', 3)
        ->where('notes.picks.0.label', 'P1p3')
        ->where('notes.picks.0.cardsInPack', 12)
        ->where('notes.picks.0.deadlineAt', now()->addSeconds(41)->toIso8601String())
        ->where('notes.picks.0.pickedName', null)
        ->where('notes.picks.0.note', null));
});

it('ships every pick so the window can walk back without a round trip', function () {
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);
    foreach (range(1, 4) as $ordinal) {
        DraftPick::factory()->for($draft)->create([
            'ordinal' => $ordinal, 'pack_number' => 1, 'pick_number' => $ordinal,
            'note' => $ordinal === 2 ? 'wheeled the bomb' : null,
        ]);
    }

    $this->get(route('overlay.draft-notes'))->assertOk()->assertInertia(fn ($page) => $page
        ->has('notes.picks', 4)
        ->where('notes.currentOrdinal', 4)
        ->where('notes.picks.1.note', 'wheeled the bomb'));
});

it('answers a partial reload of notes only', function () {
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);
    DraftPick::factory()->for($draft)->create(['ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1]);

    $response = inertiaPartial(route('overlay.draft-notes'), 'overlay/DraftNotes', ['notes']);

    $response->assertOk();
    expect($response->json('props.notes.currentOrdinal'))->toBe(1)
        ->and($response->json('props'))->not->toHaveKey('serverNow');
});
