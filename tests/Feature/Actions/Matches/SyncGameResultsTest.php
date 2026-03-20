<?php

use App\Actions\Matches\SyncGameResults;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createMatchForSync(array $overrides = []): MtgoMatch
{
    return MtgoMatch::create(array_merge([
        'mtgo_id' => (string) rand(10000, 99999),
        'token' => 'token-'.uniqid(),
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'started_at' => now()->subHour(),
        'ended_at' => now(),
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
    ], $overrides));
}

function createGameForSync(MtgoMatch $match, array $overrides = []): Game
{
    return Game::create(array_merge([
        'match_id' => $match->id,
        'mtgo_id' => rand(100000, 999999),
        'started_at' => now()->subMinutes(30),
        'ended_at' => now()->subMinutes(15),
        'won' => false,
    ], $overrides));
}

it('corrects game won fields from log results', function () {
    $match = createMatchForSync();
    $game1 = createGameForSync($match, ['won' => false, 'started_at' => now()->subMinutes(30)]);
    $game2 = createGameForSync($match, ['won' => false, 'started_at' => now()->subMinutes(15)]);

    // Log says player won both games
    SyncGameResults::run($match, [true, true]);

    expect($game1->fresh()->won)->toBeTrue();
    expect($game2->fresh()->won)->toBeTrue();
});

it('does not update games that already match log results', function () {
    $match = createMatchForSync();
    $game1 = createGameForSync($match, ['won' => true, 'started_at' => now()->subMinutes(30)]);
    $game2 = createGameForSync($match, ['won' => false, 'started_at' => now()->subMinutes(15)]);

    SyncGameResults::run($match, [true, false]);

    expect($game1->fresh()->won)->toBeTrue();
    expect($game2->fresh()->won)->toBeFalse();
});

it('handles partial log results gracefully', function () {
    $match = createMatchForSync();
    $game1 = createGameForSync($match, ['won' => false, 'started_at' => now()->subMinutes(30)]);
    $game2 = createGameForSync($match, ['won' => false, 'started_at' => now()->subMinutes(15)]);

    // Log only has result for game 1 (game 2 ended via disconnect)
    SyncGameResults::run($match, [true]);

    expect($game1->fresh()->won)->toBeTrue();
    expect($game2->fresh()->won)->toBeFalse(); // unchanged
});

it('handles empty log results', function () {
    $match = createMatchForSync();
    $game1 = createGameForSync($match, ['won' => false, 'started_at' => now()->subMinutes(30)]);

    SyncGameResults::run($match, []);

    expect($game1->fresh()->won)->toBeFalse(); // unchanged
});

it('handles more log results than games', function () {
    $match = createMatchForSync();
    $game1 = createGameForSync($match, ['won' => false, 'started_at' => now()->subMinutes(30)]);

    // Log has 3 results but only 1 game record exists
    SyncGameResults::run($match, [true, true, false]);

    expect($game1->fresh()->won)->toBeTrue();
});

it('syncs a mix of wins and losses', function () {
    $match = createMatchForSync(['outcome' => MatchOutcome::Win]);
    $game1 = createGameForSync($match, ['won' => false, 'started_at' => now()->subMinutes(45)]);
    $game2 = createGameForSync($match, ['won' => false, 'started_at' => now()->subMinutes(30)]);
    $game3 = createGameForSync($match, ['won' => false, 'started_at' => now()->subMinutes(15)]);

    SyncGameResults::run($match, [true, false, true]);

    expect($game1->fresh()->won)->toBeTrue();
    expect($game2->fresh()->won)->toBeFalse();
    expect($game3->fresh()->won)->toBeTrue();
});
