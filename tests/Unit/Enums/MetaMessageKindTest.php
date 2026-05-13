<?php

use App\Enums\MetaMessageKind;

it('has all expected cases', function () {
    $cases = collect(MetaMessageKind::cases())->map(fn ($c) => $c->value)->all();

    expect($cases)->toContain(
        'deck_list',
        'opponent_name',
        'die_roll',
        'play_choice',
        'mulligan',
        'starting_hand',
        'turn_start',
        'cast_card',
        'play_card',
        'game_winner',
        'concede',
        'joined',
        'chat',
        'ui_prompt',
        'system',
        'unknown',
    );
});
