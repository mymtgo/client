<?php

use App\Actions\Leagues\CompleteLeague;
use App\Enums\LeagueState;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks an active league as complete and stamps completed_at', function () {
    $league = League::factory()->create([
        'state' => LeagueState::Active,
        'completed_at' => null,
    ]);

    CompleteLeague::run($league);

    $league->refresh();
    expect($league->state)->toBe(LeagueState::Complete);
    expect($league->completed_at)->not->toBeNull();
});

it('is idempotent and does not overwrite completed_at on a Complete league', function () {
    $original = now()->subMinutes(30)->startOfSecond();
    $league = League::factory()->create([
        'state' => LeagueState::Complete,
        'completed_at' => $original,
    ]);

    CompleteLeague::run($league);

    $league->refresh();
    expect($league->completed_at->toDateTimeString())->toBe($original->toDateTimeString());
});
