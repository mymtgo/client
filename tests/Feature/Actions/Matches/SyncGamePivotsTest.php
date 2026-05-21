<?php

use App\Actions\Matches\SyncGamePivots;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function attachSyncPivotPlayers(Game $game, string $localUsername, string $opponentUsername, bool $localOnPlay = false, bool $opponentOnPlay = false): array
{
    $local = Player::firstOrCreate(['username' => $localUsername]);
    $opponent = Player::firstOrCreate(['username' => $opponentUsername]);

    $game->players()->attach($local->id, [
        'is_local' => true,
        'on_play' => $localOnPlay,
        'instance_id' => 1,
    ]);
    $game->players()->attach($opponent->id, [
        'is_local' => false,
        'on_play' => $opponentOnPlay,
        'instance_id' => 2,
    ]);

    return [$local, $opponent];
}

function freshSyncPivotGame(): Game
{
    $match = MtgoMatch::factory()->create();

    return Game::factory()->create([
        'match_id' => $match->id,
        'won' => null,
        'ended_at' => null,
    ]);
}

it('no-ops when game data is null', function () {
    $game = freshSyncPivotGame();
    [$local, $opponent] = attachSyncPivotPlayers($game, 'me', 'opp', localOnPlay: true);

    SyncGamePivots::forGame($game->fresh(['players']), null, 'me');

    $pivots = $game->fresh()->players->keyBy('username');
    expect((bool) $pivots['me']->pivot->on_play)->toBeTrue();
    expect((bool) $pivots['opp']->pivot->on_play)->toBeFalse();
    expect($game->fresh()->won)->toBeNull();
});

it('writes won true when local player is winner', function () {
    $game = freshSyncPivotGame();
    attachSyncPivotPlayers($game, 'me', 'opp');

    SyncGamePivots::forGame($game->fresh(['players']), [
        'winner' => 'me',
        'loser' => 'opp',
        'on_play' => null,
    ], 'me');

    expect($game->fresh()->won)->toBeTrue();
});

it('writes won false when opponent is winner', function () {
    $game = freshSyncPivotGame();
    attachSyncPivotPlayers($game, 'me', 'opp');

    SyncGamePivots::forGame($game->fresh(['players']), [
        'winner' => 'opp',
        'loser' => 'me',
        'on_play' => null,
    ], 'me');

    expect($game->fresh()->won)->toBeFalse();
});

it('skips won update when winner missing', function () {
    $game = freshSyncPivotGame();
    attachSyncPivotPlayers($game, 'me', 'opp');
    $game->update(['won' => true]);

    SyncGamePivots::forGame($game->fresh(['players']), [
        'winner' => null,
        'on_play' => null,
    ], 'me');

    expect($game->fresh()->won)->toBeTrue();
});

it('writes ended_at when source has it and game is null', function () {
    $game = freshSyncPivotGame();
    attachSyncPivotPlayers($game, 'me', 'opp');

    SyncGamePivots::forGame($game->fresh(['players']), [
        'winner' => 'me',
        'on_play' => null,
        'ended_at' => '2026-04-29T10:00:00+00:00',
    ], 'me');

    expect($game->fresh()->ended_at)->not->toBeNull();
});

it('overwrites stale ended_at with the latest from the game log', function () {
    $game = freshSyncPivotGame();
    attachSyncPivotPlayers($game, 'me', 'opp');
    $placeholder = now()->subDay();
    $game->update(['ended_at' => $placeholder]);

    SyncGamePivots::forGame($game->fresh(['players']), [
        'winner' => 'me',
        'on_play' => null,
        'ended_at' => '2026-04-29T10:00:00+00:00',
    ], 'me');

    expect($game->fresh()->ended_at->format('Y-m-d H:i:s'))->toBe('2026-04-29 10:00:00');
});

it('is a no-op when ended_at already matches the source', function () {
    $game = freshSyncPivotGame();
    attachSyncPivotPlayers($game, 'me', 'opp');
    $existing = '2026-04-29T10:00:00+00:00';
    $game->update(['ended_at' => $existing]);
    $updatedAt = $game->fresh()->updated_at;

    SyncGamePivots::forGame($game->fresh(['players']), [
        'winner' => null,
        'on_play' => null,
        'ended_at' => $existing,
    ], 'me');

    expect($game->fresh()->updated_at->timestamp)->toBe($updatedAt->timestamp);
});

it('flips on_play when local player is on play', function () {
    $game = freshSyncPivotGame();
    attachSyncPivotPlayers($game, 'me', 'opp', localOnPlay: false, opponentOnPlay: false);

    SyncGamePivots::forGame($game->fresh(['players']), [
        'winner' => null,
        'on_play' => 'me',
    ], 'me');

    $pivots = $game->fresh()->players->keyBy('username');
    expect((bool) $pivots['me']->pivot->on_play)->toBeTrue();
    expect((bool) $pivots['opp']->pivot->on_play)->toBeFalse();
});

it('flips on_play when opponent is on play', function () {
    $game = freshSyncPivotGame();
    attachSyncPivotPlayers($game, 'me', 'opp', localOnPlay: true, opponentOnPlay: false);

    SyncGamePivots::forGame($game->fresh(['players']), [
        'winner' => null,
        'on_play' => 'opp',
    ], 'me');

    $pivots = $game->fresh()->players->keyBy('username');
    expect((bool) $pivots['me']->pivot->on_play)->toBeFalse();
    expect((bool) $pivots['opp']->pivot->on_play)->toBeTrue();
});

it('leaves on_play untouched when source name missing', function () {
    $game = freshSyncPivotGame();
    attachSyncPivotPlayers($game, 'me', 'opp', localOnPlay: true, opponentOnPlay: false);

    SyncGamePivots::forGame($game->fresh(['players']), [
        'winner' => 'me',
        'on_play' => null,
    ], 'me');

    $pivots = $game->fresh()->players->keyBy('username');
    expect((bool) $pivots['me']->pivot->on_play)->toBeTrue();
    expect((bool) $pivots['opp']->pivot->on_play)->toBeFalse();
});

it('is idempotent across repeated calls', function () {
    $game = freshSyncPivotGame();
    attachSyncPivotPlayers($game, 'me', 'opp');

    $data = ['winner' => 'me', 'on_play' => 'opp'];

    SyncGamePivots::forGame($game->fresh(['players']), $data, 'me');
    SyncGamePivots::forGame($game->fresh(['players']), $data, 'me');
    SyncGamePivots::forGame($game->fresh(['players']), $data, 'me');

    $pivots = $game->fresh()->players->keyBy('username');
    expect((bool) $pivots['me']->pivot->on_play)->toBeFalse();
    expect((bool) $pivots['opp']->pivot->on_play)->toBeTrue();
    expect($game->fresh()->won)->toBeTrue();
});

it('returns silently when local username does not match any pivot row', function () {
    $game = freshSyncPivotGame();
    attachSyncPivotPlayers($game, 'someoneA', 'someoneB');

    SyncGamePivots::forGame($game->fresh(['players']), [
        'winner' => 'someoneA',
        'on_play' => 'someoneA',
    ], 'unrelated');

    expect($game->fresh()->won)->toBeNull();
});
