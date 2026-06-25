<?php

use App\Actions\Matches\SyncGamePivots;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function freshSyncPivotGame(): Game
{
    $match = MtgoMatch::factory()->create();

    return Game::factory()->create([
        'match_id' => $match->id,
        'won' => null,
        'ended_at' => null,
        'local_on_play' => null,
        'local_instance' => 1, // local player is a participant; required by the guard
    ]);
}

// ---------------------------------------------------------------------------
// No-ops
// ---------------------------------------------------------------------------

it('no-ops when game data is null', function () {
    $game = freshSyncPivotGame();
    $game->update(['local_on_play' => true, 'won' => null]);

    SyncGamePivots::forGame($game->fresh(), null, 'me');

    $fresh = $game->fresh();
    expect($fresh->local_on_play)->toBeTrue();
    expect($fresh->won)->toBeNull();
});

// ---------------------------------------------------------------------------
// won
// ---------------------------------------------------------------------------

it('writes won true when local player is winner', function () {
    $game = freshSyncPivotGame();

    SyncGamePivots::forGame($game->fresh(), [
        'winner' => 'me',
        'loser' => 'opp',
        'on_play' => null,
    ], 'me');

    expect($game->fresh()->won)->toBeTrue();
});

it('writes won false when opponent is winner', function () {
    $game = freshSyncPivotGame();

    SyncGamePivots::forGame($game->fresh(), [
        'winner' => 'opp',
        'loser' => 'me',
        'on_play' => null,
    ], 'me');

    expect($game->fresh()->won)->toBeFalse();
});

it('skips won update when winner missing', function () {
    $game = freshSyncPivotGame();
    $game->update(['won' => true]);

    SyncGamePivots::forGame($game->fresh(), [
        'winner' => null,
        'on_play' => null,
    ], 'me');

    expect($game->fresh()->won)->toBeTrue();
});

// ---------------------------------------------------------------------------
// ended_at
// ---------------------------------------------------------------------------

it('writes ended_at when source has it and game is null', function () {
    $game = freshSyncPivotGame();

    SyncGamePivots::forGame($game->fresh(), [
        'winner' => 'me',
        'on_play' => null,
        'ended_at' => '2026-04-29T10:00:00+00:00',
    ], 'me');

    expect($game->fresh()->ended_at)->not->toBeNull();
});

it('does not overwrite existing ended_at', function () {
    $game = freshSyncPivotGame();
    $original = now()->subDay();
    $game->update(['ended_at' => $original]);

    SyncGamePivots::forGame($game->fresh(), [
        'winner' => 'me',
        'on_play' => null,
        'ended_at' => '2026-04-29T10:00:00+00:00',
    ], 'me');

    expect($game->fresh()->ended_at->timestamp)->toBe($original->timestamp);
});

// ---------------------------------------------------------------------------
// local_on_play (new scalar column)
// ---------------------------------------------------------------------------

it('sets local_on_play true when local player chooses to play', function () {
    $game = freshSyncPivotGame();

    SyncGamePivots::forGame($game->fresh(), [
        'winner' => null,
        'on_play' => 'me',
    ], 'me');

    expect($game->fresh()->local_on_play)->toBeTrue();
});

it('sets local_on_play false when opponent chooses to play', function () {
    $game = freshSyncPivotGame();
    $game->update(['local_on_play' => true]);

    SyncGamePivots::forGame($game->fresh(), [
        'winner' => null,
        'on_play' => 'opp',
    ], 'me');

    expect($game->fresh()->local_on_play)->toBeFalse();
});

it('leaves local_on_play untouched when on_play source name is null', function () {
    $game = freshSyncPivotGame();
    $game->update(['local_on_play' => true]);

    SyncGamePivots::forGame($game->fresh(), [
        'winner' => 'me',
        'on_play' => null,
    ], 'me');

    expect($game->fresh()->local_on_play)->toBeTrue();
});

it('leaves local_on_play untouched when on_play key is missing from gameData', function () {
    $game = freshSyncPivotGame();
    $game->update(['local_on_play' => true]);

    SyncGamePivots::forGame($game->fresh(), [
        'winner' => 'me',
        // 'on_play' key intentionally absent
    ], 'me');

    expect($game->fresh()->local_on_play)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

it('is idempotent across repeated calls', function () {
    $game = freshSyncPivotGame();

    $data = ['winner' => 'me', 'on_play' => 'opp'];

    SyncGamePivots::forGame($game->fresh(), $data, 'me');
    SyncGamePivots::forGame($game->fresh(), $data, 'me');
    SyncGamePivots::forGame($game->fresh(), $data, 'me');

    $fresh = $game->fresh();
    expect($fresh->local_on_play)->toBeFalse();
    expect($fresh->won)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Guard: local_instance null prevents writes (local player not a participant)
// ---------------------------------------------------------------------------

it('does not write won or local_on_play when local_instance is null', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create([
        'match_id' => $match->id,
        'won' => null,
        'ended_at' => null,
        'local_on_play' => null,
        'local_instance' => null, // local player is NOT a participant
    ]);

    SyncGamePivots::forGame($game->fresh(), [
        'winner' => 'unrelated',
        'on_play' => 'unrelated',
    ], 'unrelated');

    $fresh = $game->fresh();
    expect($fresh->won)->toBeNull();
    expect($fresh->local_on_play)->toBeNull();
});
