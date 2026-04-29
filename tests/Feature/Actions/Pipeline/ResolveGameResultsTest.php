<?php

use App\Actions\Pipeline\ResolveGameResults;
use App\Facades\Mtgo;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('syncs on_play pivots from a multi-game log via the shared action', function () {
    Mtgo::shouldReceive('resolveUsername')->andReturn('anticloser');

    $match = MtgoMatch::factory()->ended()->create([
        'token' => 'res-on-play-token',
    ]);

    $local = Player::create(['username' => 'anticloser']);
    $opponent = Player::create(['username' => 'Bordas99']);

    $game1 = Game::factory()->create([
        'match_id' => $match->id,
        'started_at' => now()->subMinutes(10),
        'won' => null,
    ]);

    $game2 = Game::factory()->create([
        'match_id' => $match->id,
        'started_at' => now()->subMinutes(5),
        'won' => null,
    ]);

    foreach ([$game1, $game2] as $g) {
        $g->players()->attach($local->id, [
            'is_local' => true,
            'on_play' => false,
            'instance_id' => 1,
        ]);
        $g->players()->attach($opponent->id, [
            'is_local' => false,
            'on_play' => false,
            'instance_id' => 2,
        ]);
    }

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => base_path('tests/fixtures/gamelogs/clean_2_0_win.dat'),
    ]);

    ResolveGameResults::run($match->fresh());

    $game1Pivots = $game1->fresh()->players->keyBy('username');
    $game2Pivots = $game2->fresh()->players->keyBy('username');

    expect((bool) $game1Pivots['anticloser']->pivot->on_play)->toBeTrue();
    expect((bool) $game1Pivots['Bordas99']->pivot->on_play)->toBeFalse();

    expect((bool) $game2Pivots['anticloser']->pivot->on_play)->toBeFalse();
    expect((bool) $game2Pivots['Bordas99']->pivot->on_play)->toBeTrue();
});

it('does not mis-align on_play across games when one game lacks chooses-to-play', function () {
    // Real-world fixture: 3 games, game 3 has no "chooses to play" line.
    // anticloser on play in g0 + g1, null in g2.
    Mtgo::shouldReceive('resolveUsername')->andReturn('anticloser');

    $match = MtgoMatch::factory()->ended()->create([
        'token' => 'res-partial-on-play',
    ]);

    $local = Player::create(['username' => 'anticloser']);
    $opponent = Player::create(['username' => 'divix10']);

    $games = collect();
    foreach ([10, 8, 5] as $i => $minutesAgo) {
        $g = Game::factory()->create([
            'match_id' => $match->id,
            'started_at' => now()->subMinutes($minutesAgo),
            'won' => null,
        ]);

        // Pre-existing pivot value mimicking the post-incident DB state where
        // every game was incorrectly marked OTD.
        $g->players()->attach($local->id, [
            'is_local' => true,
            'on_play' => false,
            'instance_id' => 1,
        ]);
        $g->players()->attach($opponent->id, [
            'is_local' => false,
            'on_play' => false,
            'instance_id' => 2,
        ]);

        $games->push($g);
    }

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => base_path('tests/fixtures/gamelogs/multi_game_partial_on_play.dat'),
    ]);

    ResolveGameResults::run($match->fresh());

    $g0 = $games[0]->fresh()->players->keyBy('username');
    $g1 = $games[1]->fresh()->players->keyBy('username');
    $g2 = $games[2]->fresh()->players->keyBy('username');

    expect((bool) $g0['anticloser']->pivot->on_play)->toBeTrue();
    expect((bool) $g1['anticloser']->pivot->on_play)->toBeTrue();
    // Game 3 has no source line — pivot must remain at its prior value
    // rather than silently being mislabeled as OTD.
    expect((bool) $g2['anticloser']->pivot->on_play)->toBeFalse();
    expect((bool) $g2['divix10']->pivot->on_play)->toBeFalse();
});

it('leaves on_play untouched when log lacks a chooses-to-play line', function () {
    Mtgo::shouldReceive('resolveUsername')->andReturn('anticloser');

    $match = MtgoMatch::factory()->ended()->create([
        'token' => 'res-no-on-play-token',
    ]);

    $local = Player::create(['username' => 'anticloser']);
    $opponent = Player::create(['username' => 'Other']);

    $game = Game::factory()->create([
        'match_id' => $match->id,
        'started_at' => now()->subMinutes(5),
        'won' => null,
    ]);

    $game->players()->attach($local->id, [
        'is_local' => true,
        'on_play' => true,
        'instance_id' => 1,
    ]);
    $game->players()->attach($opponent->id, [
        'is_local' => false,
        'on_play' => false,
        'instance_id' => 2,
    ]);

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => base_path('tests/fixtures/gamelogs/instant_concede.dat'),
    ]);

    ResolveGameResults::run($match->fresh());

    $pivots = $game->fresh()->players->keyBy('username');

    expect((bool) $pivots['anticloser']->pivot->on_play)->toBeTrue();
    expect((bool) $pivots['Other']->pivot->on_play)->toBeFalse();
});
