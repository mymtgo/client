<?php

use App\Actions\Matches\ParseMatchHistory;
use App\Actions\Matches\ReconcileStuckMatches;
use App\Enums\MatchState;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->mock = Mockery::mock('alias:'.ParseMatchHistory::class);
});

it('resolves an InProgress match older than 10 minutes via history', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'updated_at' => now()->subMinutes(15),
        'failed_at' => null,
    ]);

    $this->mock->shouldReceive('findResult')
        ->with($match->mtgo_id)
        ->andReturn(['wins' => 2, 'losses' => 1]);

    ReconcileStuckMatches::run();

    expect($match->fresh()->state)->toBe(MatchState::Complete);
});

it('leaves matches alone when no history result is available', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'updated_at' => now()->subMinutes(15),
        'failed_at' => null,
    ]);

    $this->mock->shouldReceive('findResult')->andReturn(null);

    ReconcileStuckMatches::run();

    expect($match->fresh()->state)->toBe(MatchState::InProgress);
});

it('skips matches with failed_at set', function () {
    MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'updated_at' => now()->subMinutes(20),
        'failed_at' => now(),
    ]);

    $this->mock->shouldNotReceive('findResult');

    ReconcileStuckMatches::run();
});

it('skips matches that are recently updated', function () {
    MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'updated_at' => now()->subMinutes(1),
    ]);

    $this->mock->shouldNotReceive('findResult');

    ReconcileStuckMatches::run();
});

it('caps the number of matches processed per invocation', function () {
    MtgoMatch::factory()
        ->count(60)
        ->create([
            'state' => MatchState::InProgress,
            'updated_at' => now()->subMinutes(15),
        ]);

    $this->mock->shouldReceive('findResult')->times(50)->andReturn(null);

    ReconcileStuckMatches::run();
});
