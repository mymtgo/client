<?php

use App\Actions\Drafts\AbandonStaleDrafts;
use App\Enums\DraftState;
use App\Models\Draft;
use App\Models\DraftPick;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('abandons a picking draft with no pick activity for 30 minutes', function () {
    $stale = Draft::factory()->create(['state' => DraftState::Picking]);
    DraftPick::factory()->for($stale)->create(['ordinal' => 1, 'shown_at' => now()->subMinutes(45)]);

    $live = Draft::factory()->create(['state' => DraftState::Picking]);
    DraftPick::factory()->for($live)->create(['ordinal' => 1, 'shown_at' => now()->subMinutes(5)]);

    $finished = Draft::factory()->finished()->create();

    AbandonStaleDrafts::run();

    expect($stale->fresh()->state)->toBe(DraftState::Abandoned)
        ->and($live->fresh()->state)->toBe(DraftState::Picking)
        ->and($finished->fresh()->state)->toBe(DraftState::Finished);
});

it('uses the draft start when no pick was ever shown', function () {
    $draft = Draft::factory()->create(['state' => DraftState::Connecting, 'started_at' => now()->subHour()]);

    AbandonStaleDrafts::run();

    expect($draft->fresh()->state)->toBe(DraftState::Abandoned);
});

it('counts a recent pick as activity even when the pack was shown long ago', function () {
    // MTGO can log a commit long after the pack appeared, and the next
    // pack may never arrive, so shown_at alone reads a live draft as dead.
    $draft = Draft::factory()->create(['state' => DraftState::Picking, 'started_at' => now()->subHours(2)]);
    DraftPick::factory()->for($draft)->create([
        'ordinal' => 1,
        'shown_at' => now()->subMinutes(45),
        'picked_at' => now()->subMinutes(2),
    ]);

    AbandonStaleDrafts::run();

    expect($draft->fresh()->state)->toBe(DraftState::Picking);
});

it('falls back to created_at for a connecting draft with no picks and no start', function () {
    $draft = Draft::factory()->create(['state' => DraftState::Connecting, 'started_at' => null]);
    $draft->forceFill(['created_at' => now()->subHours(2)])->save();

    AbandonStaleDrafts::run();

    expect($draft->fresh()->state)->toBe(DraftState::Abandoned);
});
