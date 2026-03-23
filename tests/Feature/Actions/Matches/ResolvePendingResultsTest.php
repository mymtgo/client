<?php

use App\Actions\Matches\ResolvePendingResults;
use App\Enums\MatchState;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('leaves PendingResult matches unchanged when parser returns null', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::PendingResult]);

    ResolvePendingResults::run();

    expect($match->fresh()->state)->toBe(MatchState::PendingResult);
});

it('skips matches not in PendingResult state', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::Ended]);

    ResolvePendingResults::run();

    expect($match->fresh()->state)->toBe(MatchState::Ended);
});

it('does nothing when no PendingResult matches exist', function () {
    ResolvePendingResults::run();

    expect(MtgoMatch::count())->toBe(0);
});
