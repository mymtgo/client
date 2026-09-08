<?php

use App\Actions\Matches\BuildMatchShowProps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function sideboardUrl($game): string
{
    return "/games/{$game->id}/sideboard";
}

it('writes a full postboard deck_json and shows the diff', function () {
    $fx = createManualMatchFixture();
    $game2 = $fx['games'][1];

    $this->put(sideboardUrl($game2), ['changes' => [
        ['mtgo_id' => 4001, 'quantity' => 2, 'type' => 'out'],
        ['mtgo_id' => 4003, 'quantity' => 2, 'type' => 'in'],
    ]])->assertRedirect()->assertSessionHas('success', 'Sideboard saved.');

    $deck = collect(json_decode(DB::table('game_player')->where('game_id', $game2->id)->where('is_local', true)->value('deck_json'), true));
    expect($deck->where('sideboard', false)->pluck('quantity', 'mtgo_id')->all())->toBe([4001 => 2, 4002 => 4, 4004 => 20, 4003 => 2])
        ->and($deck->where('sideboard', true)->pluck('quantity', 'mtgo_id')->all())->toBe([4003 => 1, 4001 => 2]);

    $games = BuildMatchShowProps::run($fx['match']->fresh())['games'];
    expect(collect($games[1]['sideboardChanges'])->map(fn ($c) => [$c['mtgoId'], $c['type'], $c['quantity']])->all())
        ->toBe([[4003, 'in', 2], [4001, 'out', 2]]);

    $rows = DB::table('card_game_stats')->where('game_id', $game2->id)->where('opponent', false)->get()->keyBy('oracle_id');
    expect((bool) $rows['o-bolt']->sided_out)->toBeTrue()
        ->and((bool) $rows['o-sb']->sided_in)->toBeTrue()
        ->and((bool) DB::table('card_game_stats')->where('game_id', $fx['games'][0]->id)->where('oracle_id', 'o-bolt')->value('sided_out'))->toBeFalse();
});

it('rejects game one, unknown cards, over quantity and duplicates', function () {
    $fx = createManualMatchFixture();
    [$game1, $game2] = [$fx['games'][0], $fx['games'][1]];

    $this->put(sideboardUrl($game1), ['changes' => [['mtgo_id' => 4001, 'quantity' => 1, 'type' => 'out']]])->assertSessionHasErrors('changes');
    $this->put(sideboardUrl($game2), ['changes' => [['mtgo_id' => 9999, 'quantity' => 1, 'type' => 'out']]])->assertSessionHasErrors('changes');
    $this->put(sideboardUrl($game2), ['changes' => [['mtgo_id' => 4003, 'quantity' => 1, 'type' => 'out']]])->assertSessionHasErrors('changes');
    $this->put(sideboardUrl($game2), ['changes' => [['mtgo_id' => 4001, 'quantity' => 1, 'type' => 'in']]])->assertSessionHasErrors('changes');
    $this->put(sideboardUrl($game2), ['changes' => [['mtgo_id' => 4001, 'quantity' => 5, 'type' => 'out']]])->assertSessionHasErrors('changes');
    $this->put(sideboardUrl($game2), ['changes' => [['mtgo_id' => 4003, 'quantity' => 4, 'type' => 'in']]])->assertSessionHasErrors('changes');
    $this->put(sideboardUrl($game2), ['changes' => [
        ['mtgo_id' => 4001, 'quantity' => 1, 'type' => 'out'],
        ['mtgo_id' => 4001, 'quantity' => 1, 'type' => 'out'],
    ]])->assertSessionHasErrors('changes');
});

it('allows uneven totals', function () {
    $fx = createManualMatchFixture();

    $this->put(sideboardUrl($fx['games'][1]), ['changes' => [['mtgo_id' => 4001, 'quantity' => 1, 'type' => 'out']]])->assertSessionHasNoErrors();
});

it('clears a kept hand that used a sided-out card', function () {
    $fx = createManualMatchFixture();
    $game2 = $fx['games'][1];
    $game2->players()->updateExistingPivot($fx['local']->id, ['opening_hand_json' => ['kept' => [4001, 4004, 4004, 4004, 4004, 4004, 4004], 'bottomed' => [], 'mulligans' => []], 'mulligan_count' => 0]);

    $this->put(sideboardUrl($game2), ['changes' => [['mtgo_id' => 4001, 'quantity' => 4, 'type' => 'out']]])
        ->assertSessionHas('success', 'Sideboard saved. Opening hand cleared because it used a card you sided out.');

    expect(DB::table('game_player')->where('game_id', $game2->id)->where('is_local', true)->value('opening_hand_json'))->toBeNull();
});

it('keeps a hand that still fits and resets with empty changes', function () {
    $fx = createManualMatchFixture();
    $game2 = $fx['games'][1];
    $game2->players()->updateExistingPivot($fx['local']->id, ['opening_hand_json' => ['kept' => [4001, 4004, 4004, 4004, 4004, 4004, 4004], 'bottomed' => [], 'mulligans' => []], 'mulligan_count' => 0]);

    $this->put(sideboardUrl($game2), ['changes' => [['mtgo_id' => 4001, 'quantity' => 2, 'type' => 'out']]])->assertSessionHas('success', 'Sideboard saved.');
    expect(json_decode(DB::table('game_player')->where('game_id', $game2->id)->where('is_local', true)->value('opening_hand_json'), true)['kept'])->toHaveCount(7);

    $this->put(sideboardUrl($game2), ['changes' => []])->assertSessionHasNoErrors();
    expect(json_decode(DB::table('game_player')->where('game_id', $game2->id)->where('is_local', true)->value('deck_json'), true))->toBe([]);
});

it('forbids tracked matches', function () {
    $fx = createManualMatchFixture(manual: false);
    $this->put(sideboardUrl($fx['games'][1]), ['changes' => []])->assertForbidden();
});

it('clears an opening hand whose mulliganed or bottomed cards were sided out', function () {
    $fx = createManualMatchFixture();
    $game2 = $fx['games'][1];
    $game2->players()->updateExistingPivot($fx['local']->id, [
        'opening_hand_json' => [
            'kept' => [4004, 4004, 4004, 4004, 4004, 4004],
            'bottomed' => [4004],
            'mulligans' => [[4002, 4004, 4004, 4004, 4004, 4004, 4004]],
        ],
        'mulligan_count' => 1,
    ]);

    $this->put(sideboardUrl($game2), ['changes' => [['mtgo_id' => 4002, 'quantity' => 4, 'type' => 'out']]])
        ->assertSessionHas('success', 'Sideboard saved. Opening hand cleared because it used a card you sided out.');

    $pivot = DB::table('game_player')->where('game_id', $game2->id)->where('is_local', true)->first();
    expect($pivot->opening_hand_json)->toBeNull()->and($pivot->mulligan_count)->toBe(0);
});

it('clears an opening hand that only fit the postboard deck when changes are reset', function () {
    $fx = createManualMatchFixture();
    $game2 = $fx['games'][1];
    $this->put(sideboardUrl($game2), ['changes' => [['mtgo_id' => 4001, 'quantity' => 3, 'type' => 'out'], ['mtgo_id' => 4003, 'quantity' => 3, 'type' => 'in']]])->assertSessionHasNoErrors();
    $game2->players()->updateExistingPivot($fx['local']->id, [
        'opening_hand_json' => ['kept' => [4003, 4004, 4004, 4004, 4004, 4004, 4004], 'bottomed' => [], 'mulligans' => []],
        'mulligan_count' => 0,
    ]);

    $this->put(sideboardUrl($game2), ['changes' => []])
        ->assertSessionHas('success', 'Sideboard saved. Opening hand cleared because it used a card you sided out.');

    expect(DB::table('game_player')->where('game_id', $game2->id)->where('is_local', true)->value('opening_hand_json'))->toBeNull();
});
