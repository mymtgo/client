<?php

use App\Actions\Cards\UpdateGameMetaFromLog;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @return array{0: Game, 1: Player, 2: Player}
 */
function ugmfl_gameWithPlayers(): array
{
    $deckVersion = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $deckVersion->id,
        'state' => 'complete',
    ]);
    $game = Game::factory()->for($match, 'match')->create(['started_at' => now()]);

    $local = Player::create(['username' => 'testplayer']);
    $opponent = Player::create(['username' => 'opponent']);

    $game->players()->attach($local->id, ['instance_id' => 0, 'is_local' => true, 'on_play' => true, 'deck_json' => []]);
    $game->players()->attach($opponent->id, ['instance_id' => 1, 'is_local' => false, 'on_play' => false, 'deck_json' => []]);

    $game->load('players');

    return [$game, $local, $opponent];
}

it('records mulligan counts for a game with no dice roll (after game 1)', function () {
    [$game, $local, $opponent] = ugmfl_gameWithPlayers();

    // Games after game 1 have no opening dice roll, but players still mulligan.
    $gameLogStats = ['game_meta' => [[
        'turn_count' => 9,
        'dice_rolls' => [],
        'mulligans' => ['testplayer' => 2, 'opponent' => 1],
    ]]];

    UpdateGameMetaFromLog::run($game, $gameLogStats, 0);

    $localMulls = DB::table('game_player')->where('game_id', $game->id)->where('player_id', $local->id)->value('mulligan_count');
    $oppMulls = DB::table('game_player')->where('game_id', $game->id)->where('player_id', $opponent->id)->value('mulligan_count');

    expect((int) $localMulls)->toBe(2);
    expect((int) $oppMulls)->toBe(1);
});

it('records both dice roll and mulligan counts for game 1', function () {
    [$game, $local, $opponent] = ugmfl_gameWithPlayers();

    $gameLogStats = ['game_meta' => [[
        'turn_count' => 7,
        'dice_rolls' => ['testplayer' => 18, 'opponent' => 12],
        'mulligans' => ['testplayer' => 1, 'opponent' => 0],
    ]]];

    UpdateGameMetaFromLog::run($game, $gameLogStats, 0);

    $localRow = DB::table('game_player')->where('game_id', $game->id)->where('player_id', $local->id)->first();

    expect((int) $localRow->mulligan_count)->toBe(1);
    expect((int) $localRow->dice_roll)->toBe(18);
});
