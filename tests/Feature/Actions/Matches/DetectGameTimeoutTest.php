<?php

use App\Actions\Matches\DetectGameTimeout;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function timeoutGame(?bool $won, ?int $localClock, ?int $opponentClock): Game
{
    $game = Game::factory()->create(['match_id' => MtgoMatch::factory()->create()->id, 'won' => $won]);
    $game->players()->attach(Player::factory()->create()->id, ['instance_id' => 0, 'is_local' => true, 'clock_remaining_ms_end' => $localClock]);
    $game->players()->attach(Player::factory()->create()->id, ['instance_id' => 1, 'is_local' => false, 'clock_remaining_ms_end' => $opponentClock]);

    return $game->load('players');
}

it('detects a timeout for the side that lost with no clock left', function (?bool $won, ?int $local, ?int $opponent, bool $side, ?bool $expected) {
    expect(DetectGameTimeout::run(timeoutGame($won, $local, $opponent), $side))->toBe($expected);
})->with([
    'local lost at 0' => [false, 0, 500000, true, true],
    'local lost at the threshold' => [false, 1000, 500000, true, true],
    'local lost just over the threshold' => [false, 1001, 500000, true, false],
    'local won at 0 (opponent conceded)' => [true, 0, 500000, true, false],
    'opponent lost at 0' => [true, 500000, 0, false, true],
    'opponent won at 0' => [false, 500000, 0, false, false],
    'result unknown' => [null, 0, 500000, true, null],
    'local clock unknown' => [false, null, 500000, true, null],
    'opponent clock unknown, local known' => [true, 500000, null, false, null],
]);
