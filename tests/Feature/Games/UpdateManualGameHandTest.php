<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function handUrl($game): string
{
    return "/games/{$game->id}/hand";
}

function sevenLands(): array
{
    return array_fill(0, 7, 4004);
}

function localPivot($game): object
{
    return DB::table('game_player')->where('game_id', $game->id)->where('is_local', true)->first();
}

it('saves kept, bottomed and mulliganed cards and counts only kept cards in stats', function () {
    $fx = createManualMatchFixture();
    $game = $fx['games'][0];

    $this->put(handUrl($game), [
        'mulligan_count' => 1,
        'kept_hand' => [4001, 4001, 4002, 4004, 4004, 4004],
        'bottomed' => [4002],
        'mulliganed_hands' => [[4004, 4004, 4004, 4004, 4004, 4004, 4001]],
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Opening hand saved.');

    $pivot = localPivot($game);
    expect(json_decode($pivot->opening_hand_json, true))->toBe([
        'kept' => [4001, 4001, 4002, 4004, 4004, 4004],
        'bottomed' => [4002],
        'mulligans' => [[4004, 4004, 4004, 4004, 4004, 4004, 4001]],
    ])
        ->and($pivot->mulligan_count)->toBe(1)
        ->and($pivot->starting_hand_size)->toBe(6);

    $kept = fn (string $oracle) => DB::table('card_game_stats')->where('game_id', $game->id)->where('oracle_id', $oracle)->value('kept');
    expect($kept('o-bolt'))->toBe(2)->and($kept('o-goyf'))->toBe(1);
});

it('accepts an empty mulliganed hand when the user does not remember it', function () {
    $fx = createManualMatchFixture();
    $game = $fx['games'][0];

    $this->put(handUrl($game), [
        'mulligan_count' => 2,
        'kept_hand' => [4001, 4002, 4004, 4004, 4004],
        'bottomed' => [4004, 4004],
        'mulliganed_hands' => [[], sevenLands()],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(json_decode(localPivot($game)->opening_hand_json, true)['mulligans'])->toBe([[], sevenLands()]);
});

it('requires a seven card final hand and no extra draw on the draw', function () {
    $fx = createManualMatchFixture();
    $game2 = $fx['games'][1];

    $this->put(handUrl($game2), ['mulligan_count' => 0, 'kept_hand' => array_fill(0, 8, 4004), 'bottomed' => [], 'mulliganed_hands' => []])
        ->assertSessionHasErrors('kept_hand');
    $this->put(handUrl($game2), ['mulligan_count' => 0, 'kept_hand' => array_fill(0, 6, 4004), 'bottomed' => [], 'mulliganed_hands' => []])
        ->assertSessionHasErrors('kept_hand');
    $this->put(handUrl($game2), ['mulligan_count' => 0, 'kept_hand' => sevenLands(), 'bottomed' => [], 'mulliganed_hands' => []])
        ->assertSessionHasNoErrors();
});

it('requires bottomed and mulliganed hand counts to match the mulligan count', function () {
    $fx = createManualMatchFixture();
    $game = $fx['games'][0];
    $six = array_fill(0, 6, 4004);

    $this->put(handUrl($game), ['mulligan_count' => 1, 'kept_hand' => sevenLands(), 'bottomed' => [], 'mulliganed_hands' => [[]]])
        ->assertSessionHasErrors('bottomed');
    $this->put(handUrl($game), ['mulligan_count' => 1, 'kept_hand' => $six, 'bottomed' => [4004], 'mulliganed_hands' => []])
        ->assertSessionHasErrors('mulliganed_hands');
    $this->put(handUrl($game), ['mulligan_count' => 1, 'kept_hand' => $six, 'bottomed' => [4004], 'mulliganed_hands' => [[4004, 4004]]])
        ->assertSessionHasErrors('mulliganed_hands.0');
});

it('rejects unknown cards and exceeded quantities in any hand', function () {
    $fx = createManualMatchFixture();
    $game = $fx['games'][0];

    $this->put(handUrl($game), ['mulligan_count' => 0, 'kept_hand' => [9999, 4004, 4004, 4004, 4004, 4004, 4004], 'bottomed' => [], 'mulliganed_hands' => []])
        ->assertSessionHasErrors('kept_hand');
    $this->put(handUrl($game), ['mulligan_count' => 0, 'kept_hand' => [4001, 4001, 4001, 4001, 4001, 4004, 4004], 'bottomed' => [], 'mulliganed_hands' => []])
        ->assertSessionHasErrors('kept_hand');
    $this->put(handUrl($game), ['mulligan_count' => 0, 'kept_hand' => [4003, 4004, 4004, 4004, 4004, 4004, 4004], 'bottomed' => [], 'mulliganed_hands' => []])
        ->assertSessionHasErrors('kept_hand');
    $this->put(handUrl($game), ['mulligan_count' => 1, 'kept_hand' => [4001, 4001, 4001, 4001, 4004, 4004], 'bottomed' => [4001], 'mulliganed_hands' => [[]]])
        ->assertSessionHasErrors('kept_hand');
    $this->put(handUrl($game), ['mulligan_count' => 1, 'kept_hand' => array_fill(0, 6, 4004), 'bottomed' => [4004], 'mulliganed_hands' => [[4003, 4004, 4004, 4004, 4004, 4004, 4004]]])
        ->assertSessionHasErrors('mulliganed_hands.0');
});

it('validates against the postboard maindeck when set', function () {
    $fx = createManualMatchFixture();
    $game2 = $fx['games'][1];
    $game2->players()->updateExistingPivot($fx['local']->id, [
        'deck_json' => [
            ['mtgo_id' => 4001, 'quantity' => 4, 'sideboard' => false],
            ['mtgo_id' => 4004, 'quantity' => 20, 'sideboard' => false],
            ['mtgo_id' => 4003, 'quantity' => 3, 'sideboard' => false],
            ['mtgo_id' => 4002, 'quantity' => 4, 'sideboard' => true],
        ],
    ]);

    $this->put(handUrl($game2), ['mulligan_count' => 0, 'kept_hand' => [4003, 4004, 4004, 4004, 4004, 4004, 4004], 'bottomed' => [], 'mulliganed_hands' => []])->assertSessionHasNoErrors();
    $this->put(handUrl($game2), ['mulligan_count' => 0, 'kept_hand' => [4002, 4004, 4004, 4004, 4004, 4004, 4004], 'bottomed' => [], 'mulliganed_hands' => []])->assertSessionHasErrors('kept_hand');
});

it('clears the hand with an empty payload', function () {
    $fx = createManualMatchFixture();
    $game = $fx['games'][0];
    $game->players()->updateExistingPivot($fx['local']->id, [
        'opening_hand_json' => ['kept' => [4004], 'bottomed' => [], 'mulligans' => []],
        'mulligan_count' => 3,
    ]);

    $this->put(handUrl($game), ['mulligan_count' => 0, 'kept_hand' => []])->assertRedirect()->assertSessionHasNoErrors();

    $pivot = localPivot($game);
    expect($pivot->opening_hand_json)->toBeNull()->and($pivot->mulligan_count)->toBe(0)->and($pivot->starting_hand_size)->toBe(7);
});

it('forbids tracked and imported matches', function () {
    $tracked = createManualMatchFixture(manual: false);
    $payload = ['mulligan_count' => 0, 'kept_hand' => sevenLands(), 'bottomed' => [], 'mulliganed_hands' => []];

    $this->put(handUrl($tracked['games'][0]), $payload)->assertForbidden();

    $tracked['match']->update(['imported' => true]);
    $this->put(handUrl($tracked['games'][0]), $payload)->assertForbidden();
});

it('is idempotent for the same payload', function () {
    $fx = createManualMatchFixture();
    $game = $fx['games'][0];
    $payload = ['mulligan_count' => 0, 'kept_hand' => [4001, 4002, 4004, 4004, 4004, 4004, 4004], 'bottomed' => [], 'mulliganed_hands' => []];

    $stats = fn () => DB::table('card_game_stats')->where('game_id', $game->id)->orderBy('oracle_id')->get()->map(fn ($r) => [$r->oracle_id, $r->kept])->all();

    $this->put(handUrl($game), $payload)->assertRedirect();
    $first = $stats();
    $this->put(handUrl($game), $payload)->assertRedirect();
    $second = $stats();

    expect($second)->toBe($first)->and(count($second))->toBe(3);
});
