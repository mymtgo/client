<?php

use App\Actions\Matches\ResolveOpponentColorIdentities;
use App\Models\Card;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Attach one local and one opponent seat to a new game on $match, with the
 * opponent's revealed cards recorded on the pivot.
 *
 * @param  array<int, array{mtgo_id: int, quantity: int}>  $opponentDeckJson
 */
function seatOpponent(MtgoMatch $match, array $opponentDeckJson): void
{
    $game = Game::factory()->create(['match_id' => $match->id]);

    $game->players()->attach(Player::factory()->create()->id, [
        'instance_id' => 2,
        'is_local' => true,
        'deck_json' => [],
    ]);

    $game->players()->attach(Player::factory()->create()->id, [
        'instance_id' => 1,
        'is_local' => false,
        'deck_json' => $opponentDeckJson,
    ]);
}

it('unions the colours of every card the opponent revealed', function () {
    Card::factory()->create(['mtgo_id' => 1001, 'color_identity' => 'U']);
    Card::factory()->create(['mtgo_id' => 1002, 'color_identity' => 'R']);

    $match = MtgoMatch::factory()->create();
    seatOpponent($match, [['mtgo_id' => 1001, 'quantity' => 2]]);
    seatOpponent($match, [['mtgo_id' => 1002, 'quantity' => 1]]);

    expect(ResolveOpponentColorIdentities::run([$match->id]))->toBe([$match->id => 'U,R']);
});

it('orders colours WUBRG regardless of reveal order', function () {
    Card::factory()->create(['mtgo_id' => 2001, 'color_identity' => 'G']);
    Card::factory()->create(['mtgo_id' => 2002, 'color_identity' => 'W']);
    Card::factory()->create(['mtgo_id' => 2003, 'color_identity' => 'B']);

    $match = MtgoMatch::factory()->create();
    seatOpponent($match, [
        ['mtgo_id' => 2001, 'quantity' => 1],
        ['mtgo_id' => 2002, 'quantity' => 1],
        ['mtgo_id' => 2003, 'quantity' => 1],
    ]);

    expect(ResolveOpponentColorIdentities::run([$match->id]))->toBe([$match->id => 'W,B,G']);
});

it('splits a multicolour card into its colours', function () {
    Card::factory()->create(['mtgo_id' => 3001, 'color_identity' => 'U,B']);

    $match = MtgoMatch::factory()->create();
    seatOpponent($match, [['mtgo_id' => 3001, 'quantity' => 1]]);

    expect(ResolveOpponentColorIdentities::run([$match->id]))->toBe([$match->id => 'U,B']);
});

it('omits a match whose opponent only revealed colourless cards', function () {
    Card::factory()->create(['mtgo_id' => 4001, 'color_identity' => '']);
    Card::factory()->create(['mtgo_id' => 4002, 'color_identity' => null]);

    $match = MtgoMatch::factory()->create();
    seatOpponent($match, [
        ['mtgo_id' => 4001, 'quantity' => 1],
        ['mtgo_id' => 4002, 'quantity' => 1],
    ]);

    expect(ResolveOpponentColorIdentities::run([$match->id]))->toBe([]);
});

it('ignores cards the catalog has never seen', function () {
    Card::factory()->create(['mtgo_id' => 5001, 'color_identity' => 'G']);

    $match = MtgoMatch::factory()->create();
    seatOpponent($match, [
        ['mtgo_id' => 5001, 'quantity' => 1],
        ['mtgo_id' => 999999, 'quantity' => 1],
    ]);

    expect(ResolveOpponentColorIdentities::run([$match->id]))->toBe([$match->id => 'G']);
});

it('never reads the local seat', function () {
    Card::factory()->create(['mtgo_id' => 6001, 'color_identity' => 'W']);

    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id]);
    $game->players()->attach(Player::factory()->create()->id, [
        'instance_id' => 2,
        'is_local' => true,
        'deck_json' => [['mtgo_id' => 6001, 'quantity' => 4]],
    ]);

    expect(ResolveOpponentColorIdentities::run([$match->id]))->toBe([]);
});

it('keys results per match', function () {
    Card::factory()->create(['mtgo_id' => 7001, 'color_identity' => 'R']);
    Card::factory()->create(['mtgo_id' => 7002, 'color_identity' => 'U']);

    $first = MtgoMatch::factory()->create();
    $second = MtgoMatch::factory()->create();
    seatOpponent($first, [['mtgo_id' => 7001, 'quantity' => 1]]);
    seatOpponent($second, [['mtgo_id' => 7002, 'quantity' => 1]]);

    expect(ResolveOpponentColorIdentities::run([$first->id, $second->id]))
        ->toBe([$first->id => 'R', $second->id => 'U']);
});

it('returns nothing for an empty id list', function () {
    expect(ResolveOpponentColorIdentities::run([]))->toBe([]);
});
