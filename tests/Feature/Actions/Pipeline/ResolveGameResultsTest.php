<?php

use App\Actions\Pipeline\ResolveGameResults;
use App\Facades\Mtgo;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('syncs on_play pivots for both players using the latest game log', function () {
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

    // Fixture: game 1 — anticloser on play; game 2 — Bordas99 on play.
    expect((bool) $game1Pivots['anticloser']->pivot->on_play)->toBeTrue();
    expect((bool) $game1Pivots['Bordas99']->pivot->on_play)->toBeFalse();

    expect((bool) $game2Pivots['anticloser']->pivot->on_play)->toBeFalse();
    expect((bool) $game2Pivots['Bordas99']->pivot->on_play)->toBeTrue();
});

it('leaves on_play untouched when the game log lacks a chooses-to-play line', function () {
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

    // instant_concede fixture has no "chooses to play" line — pivots should stay as-is.
    GameLog::create([
        'match_token' => $match->token,
        'file_path' => base_path('tests/fixtures/gamelogs/instant_concede.dat'),
    ]);

    ResolveGameResults::run($match->fresh());

    $pivots = $game->fresh()->players->keyBy('username');

    expect((bool) $pivots['anticloser']->pivot->on_play)->toBeTrue();
    expect((bool) $pivots['Other']->pivot->on_play)->toBeFalse();
});
