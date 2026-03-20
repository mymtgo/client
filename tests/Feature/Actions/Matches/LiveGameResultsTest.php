<?php

use App\Actions\Matches\SyncLiveGameResults;
use App\Models\Account;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function callSyncLiveGameResults(MtgoMatch $match): void
{
    SyncLiveGameResults::run($match);
}

it('updates game.won when game log has results', function () {
    Account::registerAndActivate('anticloser');

    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_0_win.dat');
    $match = MtgoMatch::factory()->create(['state' => 'in_progress']);

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => $fixturePath,
    ]);

    // Create two games with won = null (simulating mid-match state)
    $game1 = Game::create(['match_id' => $match->id, 'mtgo_id' => 1001, 'won' => null, 'started_at' => now()->subMinutes(30)]);
    $game2 = Game::create(['match_id' => $match->id, 'mtgo_id' => 1002, 'won' => null, 'started_at' => now()->subMinutes(15)]);

    callSyncLiveGameResults($match);

    $game1->refresh();
    $game2->refresh();

    // Both games should now have results (2-0 win fixture)
    expect($game1->won)->toBeTrue();
    expect($game2->won)->toBeTrue();
});

it('leaves games as null when game log has no result for that index', function () {
    Account::registerAndActivate('anticloser');

    // Use the disconnect fixture — only 1 game result
    $fixturePath = base_path('tests/fixtures/gamelogs/disconnect_game1.dat');
    $match = MtgoMatch::factory()->create(['state' => 'in_progress']);

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => $fixturePath,
    ]);

    $game1 = Game::create(['match_id' => $match->id, 'mtgo_id' => 2001, 'won' => null, 'started_at' => now()->subMinutes(30)]);
    $game2 = Game::create(['match_id' => $match->id, 'mtgo_id' => 2002, 'won' => null, 'started_at' => now()->subMinutes(15)]);

    callSyncLiveGameResults($match);

    $game1->refresh();
    $game2->refresh();

    // Game 1 has a result, game 2 does not
    expect($game1->won)->toBeTrue();
    expect($game2->won)->toBeNull();
});

it('is idempotent — calling twice does not cause issues', function () {
    Account::registerAndActivate('anticloser');

    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_0_win.dat');
    $match = MtgoMatch::factory()->create(['state' => 'in_progress']);

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => $fixturePath,
    ]);

    $game1 = Game::create(['match_id' => $match->id, 'mtgo_id' => 3001, 'won' => null, 'started_at' => now()->subMinutes(30)]);

    callSyncLiveGameResults($match);
    callSyncLiveGameResults($match);

    $game1->refresh();
    expect($game1->won)->toBeTrue();
});

it('does not crash when no game log exists', function () {
    Account::registerAndActivate('anticloser');

    $match = MtgoMatch::factory()->create(['state' => 'in_progress']);

    // No GameLog record — should exit gracefully
    callSyncLiveGameResults($match);

    // No exception thrown = pass
    expect(true)->toBeTrue();
});

it('skips games that already have a result', function () {
    Account::registerAndActivate('anticloser');

    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_1_win.dat');
    $match = MtgoMatch::factory()->create(['state' => 'in_progress']);

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => $fixturePath,
    ]);

    // Game 1 already has a result set
    $game1 = Game::create(['match_id' => $match->id, 'mtgo_id' => 4001, 'won' => false, 'started_at' => now()->subMinutes(30)]);
    $game2 = Game::create(['match_id' => $match->id, 'mtgo_id' => 4002, 'won' => null, 'started_at' => now()->subMinutes(15)]);

    callSyncLiveGameResults($match);

    $game1->refresh();
    $game2->refresh();

    // Game 1 keeps its existing value (false), not overwritten
    expect($game1->won)->toBeFalse();
    // Game 2 gets the result from the log
    expect($game2->won)->not->toBeNull();
});
