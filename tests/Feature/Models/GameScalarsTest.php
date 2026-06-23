<?php

use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists and casts per-game scalar columns', function () {
    $game = Game::factory()->create([
        'match_id' => MtgoMatch::factory(),
        'local_on_play' => true,
        'local_mulligans' => 1,
        'opp_mulligans' => 0,
        'local_dice' => 19,
        'opp_dice' => 4,
        'local_instance' => 12345,
        'opp_instance' => 67890,
    ]);

    $fresh = $game->fresh();

    expect($fresh->local_on_play)->toBeTrue();
    expect($fresh->local_mulligans)->toBe(1);
    expect($fresh->opp_mulligans)->toBe(0);
    expect($fresh->local_dice)->toBe(19);
    expect($fresh->opp_instance)->toBe(67890);
});

it('derives kept hand size as 7 minus mulligans', function () {
    $game = Game::factory()->create([
        'match_id' => MtgoMatch::factory(),
        'local_mulligans' => 2,
    ]);

    expect(7 - $game->local_mulligans)->toBe(5);
});
