<?php

use App\Actions\Cards\UpdateGameMetaFromLog;
use App\Models\Account;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Game, 1: string, 2: string}
 */
function ugmfl_gameWithPlayers(): array
{
    $account = Account::factory()->create(['username' => 'testplayer', 'active' => true]);
    $opponent = Opponent::factory()->create(['username' => 'opponent']);
    $deckVersion = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $deckVersion->id,
        'state' => 'complete',
        'account_id' => $account->id,
        'opponent_id' => $opponent->id,
    ]);
    $game = Game::factory()->for($match, 'match')->create(['started_at' => now()]);

    return [$game, 'testplayer', 'opponent'];
}

it('records mulligan counts for a game with no dice roll (after game 1)', function () {
    [$game, $localName, $oppName] = ugmfl_gameWithPlayers();

    // Games after game 1 have no opening dice roll, but players still mulligan.
    $gameLogStats = ['game_meta' => [[
        'turn_count' => 9,
        'dice_rolls' => [],
        'mulligans' => ['testplayer' => 2, 'opponent' => 1],
    ]]];

    UpdateGameMetaFromLog::run($game, $gameLogStats, 0);

    $game->refresh();

    expect($game->local_mulligans)->toBe(2);
    expect($game->opp_mulligans)->toBe(1);
});

it('records both dice roll and mulligan counts for game 1', function () {
    [$game, $localName, $oppName] = ugmfl_gameWithPlayers();

    $gameLogStats = ['game_meta' => [[
        'turn_count' => 7,
        'dice_rolls' => ['testplayer' => 18, 'opponent' => 12],
        'mulligans' => ['testplayer' => 1, 'opponent' => 0],
    ]]];

    UpdateGameMetaFromLog::run($game, $gameLogStats, 0);

    $game->refresh();

    expect($game->local_mulligans)->toBe(1);
    expect($game->local_dice)->toBe(18);
    expect($game->opp_mulligans)->toBe(0);
    expect($game->opp_dice)->toBe(12);
});

it('does not clobber existing values when meta is empty', function () {
    [$game, $localName, $oppName] = ugmfl_gameWithPlayers();
    $game->update(['local_mulligans' => 3, 'opp_mulligans' => 1]);

    $gameLogStats = ['game_meta' => []];

    UpdateGameMetaFromLog::run($game, $gameLogStats, 0);

    $game->refresh();

    expect($game->local_mulligans)->toBe(3);
    expect($game->opp_mulligans)->toBe(1);
});

it('writes opponent dice and mulligans separately', function () {
    [$game, $localName, $oppName] = ugmfl_gameWithPlayers();

    $gameLogStats = ['game_meta' => [[
        'turn_count' => 5,
        'dice_rolls' => ['testplayer' => 5, 'opponent' => 20],
        'mulligans' => ['testplayer' => 0, 'opponent' => 2],
    ]]];

    UpdateGameMetaFromLog::run($game, $gameLogStats, 0);

    $game->refresh();

    expect($game->local_dice)->toBe(5);
    expect($game->opp_dice)->toBe(20);
    expect($game->local_mulligans)->toBe(0);
    expect($game->opp_mulligans)->toBe(2);
});
