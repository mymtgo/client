<?php

use App\Actions\Replays\BuildReplaySnapshot;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mymtgo\Replay\Actions\ValidateReplaySnapshot;

uses(RefreshDatabase::class);

/** Adds one frame between local.player and Opp_Name to a game. */
function snapshotFrame(Game $game): void
{
    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '2026-09-20 18:00:01',
        'content' => ['Players' => [['Id' => 1, 'Name' => 'local.player'], ['Id' => 2, 'Name' => 'Opp_Name']], 'Cards' => []],
    ]);
}

/**
 * A three-game modern match: game 1 lost, game 2 won, game 3 with no
 * recorded frames. No end times, so no game log is read.
 *
 * @return array{match: MtgoMatch, games: list<Game>}
 */
function snapshotMatch(): array
{
    $match = MtgoMatch::factory()->create(['token' => 'a1b2c3d4-0000-4000-8000-000000000001', 'format' => 'CModern']);
    $games = [
        Game::factory()->for($match, 'match')->create(['started_at' => '2026-09-20 17:00:00', 'ended_at' => null, 'won' => false]),
        Game::factory()->for($match, 'match')->create(['started_at' => '2026-09-20 18:00:00', 'ended_at' => null, 'won' => true]),
        Game::factory()->for($match, 'match')->create(['started_at' => '2026-09-20 19:00:00', 'ended_at' => null]),
    ];

    $local = Player::factory()->create(['username' => 'local.player']);
    $opponent = Player::factory()->create(['username' => 'Opp_Name']);

    foreach ($games as $game) {
        $game->players()->attach($local->id, ['instance_id' => 1, 'is_local' => true, 'on_play' => true]);
        $game->players()->attach($opponent->id, ['instance_id' => 2, 'is_local' => false, 'on_play' => false]);
    }

    snapshotFrame($games[0]);
    snapshotFrame($games[1]);

    return ['match' => $match, 'games' => $games];
}

it('builds a match snapshot the package accepts', function () {
    $snapshot = BuildReplaySnapshot::run(snapshotMatch()['match']);

    expect(ValidateReplaySnapshot::run($snapshot))->toBe($snapshot)
        ->and($snapshot['version'])->toBe(2);
});

it('includes every game with frames, numbered by its place in the match', function () {
    $games = BuildReplaySnapshot::run(snapshotMatch()['match'])['games'];

    // Game 3 has no frames, so two games travel, keeping their match numbers.
    expect(array_column($games, 'game_number'))->toBe([1, 2])
        ->and(array_column($games, 'won'))->toBe([false, true]);
});

it('records the match size and format key', function () {
    $meta = BuildReplaySnapshot::run(snapshotMatch()['match'])['meta'];

    expect($meta['games_in_match'])->toBe(3)
        ->and($meta['format'])->toBe('modern');
});

it('numbers a game by its place in the match', function () {
    $games = snapshotMatch()['games'];

    expect(BuildReplaySnapshot::gameNumber($games[1]))->toBe(2);
});

it('carries your recorded sideboard for each game', function () {
    ['match' => $match, 'games' => $games] = snapshotMatch();
    $local = $games[0]->localPlayers()->first();
    $games[0]->players()->updateExistingPivot($local->id, ['deck_json' => [
        ['mtgo_id' => 1234, 'quantity' => 4, 'sideboard' => false],
        ['mtgo_id' => 5678, 'quantity' => 2, 'sideboard' => true],
    ]]);

    $snapshot = BuildReplaySnapshot::run($match);

    expect($snapshot['games'][0]['sideboard'])->toBe([['catalog_id' => 5678, 'quantity' => 2, 'name' => null, 'type' => null, 'image' => null]])
        ->and($snapshot['games'][1])->not->toHaveKey('sideboard');
});
