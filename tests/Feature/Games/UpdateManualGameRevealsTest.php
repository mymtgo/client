<?php

use App\Actions\Matches\BuildMatchShowProps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function revealsUrl($game): string
{
    return "/games/{$game->id}/reveals";
}

it('writes opponent reveals and exposes them on the detail page and stats', function () {
    $fx = createManualMatchFixture();
    $game = $fx['games'][0];

    $this->put(revealsUrl($game), ['cards' => [
        ['mtgo_id' => 4002, 'quantity' => 2],
        ['mtgo_id' => 4004, 'quantity' => 5],
    ]])->assertRedirect()->assertSessionHas('success', 'Revealed cards saved.');

    $pivot = json_decode(DB::table('game_player')->where('game_id', $game->id)->where('is_local', false)->value('deck_json'), true);
    expect($pivot)->toBe([['mtgo_id' => 4002, 'quantity' => 2], ['mtgo_id' => 4004, 'quantity' => 5]]);

    $seen = BuildMatchShowProps::run($fx['match']->fresh())['games'][0]['opponentCardsSeen'];
    expect(collect($seen)->pluck('quantity', 'mtgoId')->all())->toBe([4002 => 2, 4004 => 5]);

    expect(DB::table('card_game_stats')->where('game_id', $game->id)->where('opponent', true)->pluck('oracle_id')->sort()->values()->all())
        ->toBe(['o-goyf', 'o-land']);
});

it('validates cards, quantities and duplicates', function () {
    $fx = createManualMatchFixture();
    $game = $fx['games'][0];

    $this->put(revealsUrl($game), ['cards' => [['mtgo_id' => 9999, 'quantity' => 1]]])->assertSessionHasErrors('cards.0.mtgo_id');
    $this->put(revealsUrl($game), ['cards' => [['mtgo_id' => 4002, 'quantity' => 0]]])->assertSessionHasErrors('cards.0.quantity');
    $this->put(revealsUrl($game), ['cards' => [['mtgo_id' => 4002, 'quantity' => 21]]])->assertSessionHasErrors('cards.0.quantity');
    $this->put(revealsUrl($game), ['cards' => [['mtgo_id' => 4002, 'quantity' => 1], ['mtgo_id' => 4002, 'quantity' => 1]]])->assertSessionHasErrors('cards');
});

it('clears reveals with an empty list', function () {
    $fx = createManualMatchFixture();
    $game = $fx['games'][0];
    $game->players()->updateExistingPivot($fx['opponent']->id, ['deck_json' => [['mtgo_id' => 4002, 'quantity' => 1]]]);

    $this->put(revealsUrl($game), ['cards' => []])->assertSessionHasNoErrors();

    expect(json_decode(DB::table('game_player')->where('game_id', $game->id)->where('is_local', false)->value('deck_json'), true))->toBe([]);
});

it('forbids tracked matches', function () {
    $fx = createManualMatchFixture(manual: false);
    $this->put(revealsUrl($fx['games'][0]), ['cards' => []])->assertForbidden();
});
