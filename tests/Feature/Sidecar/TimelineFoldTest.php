<?php

use App\Sidecar\TimelineFold;

function fold(): TimelineFold
{
    return TimelineFold::start([0 => 'local.player', 1 => 'Opp_Name']);
}

function battlefieldCard(int $id = 447, int $owner = 1): array
{
    return ['c' => (string) $id, 'from' => 'Nowhere', 'to' => 'Battlefield', 'owner_p' => $owner, 'controller_p' => $owner, 'name' => 'Solitude', 'catalog_id' => 127507];
}

it('starts with two players at 20 life and no cards', function () {
    $frame = fold()->frame();

    expect($frame['Players'])->toBe([
        ['Id' => 0, 'Name' => 'local.player', 'Life' => 20, 'HandCount' => 0, 'LibraryCount' => 0],
        ['Id' => 1, 'Name' => 'Opp_Name', 'Life' => 20, 'HandCount' => 0, 'LibraryCount' => 0],
    ])->and($frame['Cards'])->toBe([])
        ->and($frame)->not->toHaveKeys(['Turn', 'Phase', 'Step', 'ActivePlayer', 'Priority']);
});

it('treats game_started as a frame change without altering state', function () {
    $f = fold();
    expect($f->apply('game_started', ['players' => [['p' => 0, 'name' => 'local.player', 'on_play' => true]], 'game_number' => 1]))->toBeTrue();
    expect($f->frame()['Players'][0]['Life'])->toBe(20);
});

it('treats game_ended as a frame change without altering state', function () {
    $f = fold();
    $f->apply('card_zone_changed', battlefieldCard());
    $f->apply('life_changed', ['p' => 0, 'life' => 3]);
    $before = $f->frame();

    expect($f->apply('game_ended', ['winner_p' => 0, 'reason' => 'result']))->toBeTrue();

    $after = $f->frame();
    expect($after['Players'])->toBe($before['Players'])
        ->and($after['Cards'])->toBe($before['Cards'])
        ->and($after)->toBe($before);
});

it('adds a card on zone change from Nowhere and drops it on zone change to Nowhere', function () {
    $f = fold();
    expect($f->apply('card_zone_changed', battlefieldCard()))->toBeTrue();
    expect($f->frame()['Cards'])->toBe([
        ['Id' => 447, 'CatalogID' => 127507, 'Zone' => 'Battlefield', 'Owner' => 1, 'Controller' => 1, 'Tapped' => false, 'Name' => 'Solitude'],
    ]);

    $f->apply('card_zone_changed', ['c' => '447', 'from' => 'Battlefield', 'to' => 'Nowhere', 'owner_p' => 1, 'controller_p' => 1, 'name' => 'Solitude', 'catalog_id' => 127507]);
    expect($f->frame()['Cards'])->toBe([]);
});

it('moves a known card between zones keeping its tapped and pt state but clearing combat', function () {
    $f = fold();
    $f->apply('card_zone_changed', battlefieldCard());
    $f->apply('card_tapped', ['c' => '447']);
    $f->apply('card_pt_changed', ['c' => '447', 'power' => 3, 'toughness' => 2]);
    $f->apply('card_attacking', ['c' => '447', 'target_p' => 0]);
    $f->apply('card_zone_changed', ['c' => '447', 'from' => 'Battlefield', 'to' => 'Graveyard', 'owner_p' => 1, 'controller_p' => 1, 'name' => 'Solitude', 'catalog_id' => 127507]);

    expect($f->frame()['Cards'][0])->toBe([
        'Id' => 447, 'CatalogID' => 127507, 'Zone' => 'Graveyard', 'Owner' => 1, 'Controller' => 1, 'Tapped' => true, 'Name' => 'Solitude', 'Power' => 3, 'Toughness' => 2,
    ]);
});

it('tracks tapped, counters, attacking, blocking and damage', function () {
    $f = fold();
    $f->apply('card_zone_changed', battlefieldCard());
    $f->apply('card_zone_changed', battlefieldCard(448, 0));

    expect($f->apply('card_tapped', ['c' => '447']))->toBeTrue();
    expect($f->apply('card_counters_changed', ['c' => '447', 'counters' => ['Time' => 5]]))->toBeTrue();
    expect($f->apply('card_attacking', ['c' => '447', 'target_p' => 0]))->toBeTrue();
    expect($f->apply('card_blocking', ['c' => '448', 'target_c' => '447']))->toBeTrue();
    expect($f->apply('card_damage', ['c' => '448', 'damage' => 2]))->toBeTrue();

    $cards = collect($f->frame()['Cards'])->keyBy('Id');
    expect($cards[447])->toMatchArray(['Tapped' => true, 'Counters' => ['Time' => 5], 'Attacking' => 0])
        ->and($cards[448])->toMatchArray(['Blocking' => 447, 'Damage' => 2]);

    $f->apply('card_untapped', ['c' => '447']);
    $f->apply('card_counters_changed', ['c' => '447', 'counters' => []]);
    expect($f->frame()['Cards'][0])->toMatchArray(['Tapped' => false])->not->toHaveKey('Counters');
});

it('clears combat keys on every card when a turn starts', function () {
    $f = fold();
    $f->apply('card_zone_changed', battlefieldCard());
    $f->apply('card_zone_changed', battlefieldCard(448, 0));
    $f->apply('card_attacking', ['c' => '447', 'target_p' => 0]);
    $f->apply('card_blocking', ['c' => '448', 'target_c' => '447']);
    $f->apply('card_damage', ['c' => '448', 'damage' => 2]);

    expect($f->apply('turn_started', ['turn' => 3, 'active_p' => 1]))->toBeTrue();

    $frame = $f->frame();
    expect($frame['Turn'])->toBe(3)->and($frame['ActivePlayer'])->toBe(1);
    foreach ($frame['Cards'] as $card) {
        expect($card)->not->toHaveKeys(['Attacking', 'Blocking', 'Damage']);
    }
});

it('tracks phase, step and priority at the frame root', function () {
    $f = fold();
    expect($f->apply('phase_changed', ['phase' => 'Combat', 'step' => 'DeclareAttackers']))->toBeTrue();
    expect($f->apply('priority_changed', ['p' => 1]))->toBeTrue();

    expect($f->frame())->toMatchArray(['Phase' => 'Combat', 'Step' => 'DeclareAttackers', 'Priority' => 1]);
});

it('tracks life, hand, library and mana pool per player', function () {
    $f = fold();
    expect($f->apply('life_changed', ['p' => 0, 'life' => 18]))->toBeTrue();
    expect($f->apply('hand_count_changed', ['p' => 0, 'count' => 5]))->toBeTrue();
    expect($f->apply('library_count_changed', ['p' => 1, 'count' => 52]))->toBeTrue();
    expect($f->apply('mana_pool_changed', ['p' => 0, 'pool' => ['W' => 1, 'U' => 1]]))->toBeTrue();

    $players = $f->frame()['Players'];
    expect($players[0])->toMatchArray(['Life' => 18, 'HandCount' => 5, 'Pool' => ['W' => 1, 'U' => 1]])
        ->and($players[1])->toMatchArray(['LibraryCount' => 52])
        ->and($players[1])->not->toHaveKey('Pool');
});

it('updates TimeLeft on clock_tick but reports no frame change', function () {
    $f = fold();
    expect($f->apply('clock_tick', ['p' => 0, 'remaining_ms' => 1482000, 'trigger' => 'priority']))->toBeFalse();
    expect($f->frame()['Players'][0]['TimeLeft'])->toBe(1482000)
        ->and($f->frame()['Players'][1])->not->toHaveKey('TimeLeft');
});

it('ignores unknown event types and events for unknown cards', function () {
    $f = fold();
    expect($f->apply('something_new', ['x' => 1]))->toBeFalse();
    expect($f->apply('card_tapped', ['c' => '999']))->toBeFalse();
    expect($f->frame()['Cards'])->toBe([]);
});

it('replaces the whole state on a keyframe', function () {
    $f = fold();
    $f->apply('card_zone_changed', battlefieldCard(100, 0));
    $f->apply('life_changed', ['p' => 0, 'life' => 5]);

    expect($f->apply('keyframe', [
        'trigger' => 'reattach', 'turn' => 4, 'phase' => 'PreCombatMain', 'step' => 'PreCombatMain', 'active_p' => 0, 'priority_p' => 0,
        'players' => [
            ['p' => 0, 'life' => 17, 'hand' => 3, 'library' => 50, 'clock_ms' => 1400000, 'pool' => []],
            ['p' => 1, 'life' => 12, 'hand' => 2, 'library' => 49, 'clock_ms' => 1300000, 'pool' => ['G' => 2]],
        ],
        'cards' => [
            ['c' => '450', 'zone' => 'Battlefield', 'owner_p' => 1, 'controller_p' => 1, 'catalog_id' => 125685, 'tapped' => true, 'name' => 'Emperor of Bones', 'power' => 2, 'toughness' => 2, 'counters' => ['Time' => 1], 'damage' => 2],
            ['c' => '451', 'zone' => 'Hand', 'owner_p' => 0, 'controller_p' => 0, 'catalog_id' => 68070, 'tapped' => false, 'name' => 'Swamp'],
            ['c' => '452', 'zone' => 'Battlefield', 'owner_p' => 0, 'controller_p' => 0, 'catalog_id' => 90210, 'tapped' => false, 'name' => 'Ephemerate', 'counters' => ['Time' => 0]],
        ],
    ]))->toBeTrue();

    $frame = $f->frame();
    expect($frame)->toMatchArray(['Turn' => 4, 'Phase' => 'PreCombatMain', 'Step' => 'PreCombatMain', 'ActivePlayer' => 0, 'Priority' => 0])
        ->and($frame['Players'])->toBe([
            ['Id' => 0, 'Name' => 'local.player', 'Life' => 17, 'HandCount' => 3, 'LibraryCount' => 50, 'TimeLeft' => 1400000, 'Pool' => []],
            ['Id' => 1, 'Name' => 'Opp_Name', 'Life' => 12, 'HandCount' => 2, 'LibraryCount' => 49, 'TimeLeft' => 1300000, 'Pool' => ['G' => 2]],
        ])
        ->and($frame['Cards'])->toBe([
            ['Id' => 450, 'CatalogID' => 125685, 'Zone' => 'Battlefield', 'Owner' => 1, 'Controller' => 1, 'Tapped' => true, 'Name' => 'Emperor of Bones', 'Power' => 2, 'Toughness' => 2, 'Counters' => ['Time' => 1], 'Damage' => 2],
            ['Id' => 451, 'CatalogID' => 68070, 'Zone' => 'Hand', 'Owner' => 0, 'Controller' => 0, 'Tapped' => false, 'Name' => 'Swamp'],
            ['Id' => 452, 'CatalogID' => 90210, 'Zone' => 'Battlefield', 'Owner' => 0, 'Controller' => 0, 'Tapped' => false, 'Name' => 'Ephemerate'],
        ]);

    expect($frame['Cards'][2])->not->toHaveKey('Counters');
});

it('updates CatalogID on card_revealed for a known card', function () {
    $f = fold();
    $f->apply('card_zone_changed', ['c' => '460', 'from' => 'Nowhere', 'to' => 'Hand', 'owner_p' => 1, 'controller_p' => 1, 'name' => '', 'catalog_id' => 0]);
    expect($f->apply('card_revealed', ['c' => '460', 'name' => 'Thoughtseize', 'catalog_id' => 40024, 'to_p' => 0]))->toBeTrue();
    expect($f->frame()['Cards'][0]['CatalogID'])->toBe(40024);

    expect($f->apply('card_revealed', ['c' => '460', 'name' => '', 'catalog_id' => 0, 'to_p' => 0]))->toBeFalse();
    expect($f->apply('card_revealed', ['c' => '460', 'name' => '', 'to_p' => 0]))->toBeFalse();
    expect($f->frame()['Cards'][0]['CatalogID'])->toBe(40024);
});

it('folds the real fixture identically from game start and from a mid-game keyframe', function () {
    $lines = array_values(array_filter(explode("\n", file_get_contents(base_path('tests/fixtures/sidecar/events-game-full.ndjson')))));
    $events = array_values(array_filter(array_map(fn ($l) => json_decode($l, true), $lines), fn ($e) => $e['game'] !== null));

    $keyframeIndexes = array_keys(array_filter($events, fn ($e) => $e['type'] === 'keyframe' && $e['data']['trigger'] === 'turn'));
    expect($keyframeIndexes)->not->toBeEmpty();
    $split = $keyframeIndexes[intdiv(count($keyframeIndexes), 2)];

    $full = fold();
    $late = fold();
    foreach ($events as $i => $e) {
        $full->apply($e['type'], $e['data']);
        if ($i >= $split) {
            $late->apply($e['type'], $e['data']);
        }
        if ($i >= $split && $e['type'] !== 'clock_tick') {
            expect($late->frame())->toBe($full->frame(), "frame diverged at event {$i} ({$e['type']})");
        }
    }
});
