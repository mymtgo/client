<?php

use App\Actions\Matches\ExtractGameHandData;
use App\Actions\Matches\ParseOpeningHand;
use App\Models\GameTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function handTimeline($game, array $localHandCatalogIds): void
{
    $cards = [];
    foreach ($localHandCatalogIds as $i => $catalogId) {
        $cards[] = ['Id' => 100 + $i, 'CatalogID' => $catalogId, 'Owner' => 0, 'Zone' => 'Hand'];
    }
    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '09:00:00',
        'content' => [
            'Players' => [
                ['Id' => 0, 'Name' => 'testplayer', 'LibraryCount' => 53, 'HandCount' => count($cards), 'Life' => 20],
                ['Id' => 1, 'Name' => 'opponent', 'LibraryCount' => 53, 'HandCount' => 7, 'Life' => 20],
            ],
            'Cards' => $cards,
        ],
    ]);
}

it('prefers the pivot opening hand over the timeline', function () {
    $fx = createManualMatchFixture();
    $game = $fx['games'][0];
    handTimeline($game, [4004, 4004, 4004, 4004, 4004, 4004, 4004]);

    $game->players()->updateExistingPivot($fx['local']->id, [
        'opening_hand_json' => [
            'kept' => [4001, 4001, 4002, 4004, 4004, 4004],
            'bottomed' => [4002],
            'mulligans' => [[4004, 4004, 4004, 4004, 4004, 4004, 4001]],
        ],
        'mulligan_count' => 1,
    ]);
    $game->players()->updateExistingPivot($fx['opponent']->id, ['mulligan_count' => 2]);
    $game->load('players', 'timeline');

    $parsed = ParseOpeningHand::run($game, 0, 1);

    expect($parsed['kept_hand'])->toBe([4001, 4001, 4002, 4004, 4004, 4004])
        ->and($parsed['mulliganed_hands'])->toBe([[4004, 4004, 4004, 4004, 4004, 4004, 4001]])
        ->and($parsed['local_mulligans'])->toBe(1)
        ->and($parsed['opponent_mulligans'])->toBe(2)
        ->and($parsed['hand_before_bottoming'])->toBe([4001, 4001, 4002, 4004, 4004, 4004, 4002])
        ->and($parsed['bottomed_instance_ids'])->toBe([6]);

    $hand = ExtractGameHandData::run($game);
    expect($hand['mulligan_count'])->toBe(1)
        ->and($hand['kept_hand'])->toBe([4001, 4001, 4002, 4004, 4004, 4004])
        ->and($hand['starting_hand_size'])->toBe(6);
});

it('keeps unrecorded mulliganed hands as empty placeholders', function () {
    $fx = createManualMatchFixture();
    $game = $fx['games'][0];

    $game->players()->updateExistingPivot($fx['local']->id, [
        'opening_hand_json' => [
            'kept' => [4004, 4004, 4004, 4004, 4004],
            'bottomed' => [4004, 4001],
            'mulligans' => [[], [4001, 4001, 4001, 4001, 4004, 4004, 4004]],
        ],
        'mulligan_count' => 2,
    ]);
    $game->load('players', 'timeline');

    $parsed = ParseOpeningHand::run($game, 0, 1);

    expect($parsed['mulliganed_hands'])->toBe([[], [4001, 4001, 4001, 4001, 4004, 4004, 4004]])
        ->and($parsed['local_mulligans'])->toBe(2)
        ->and($parsed['bottomed_instance_ids'])->toBe([5, 6]);
});

it('falls back to the timeline when the pivot hand is null', function () {
    $fx = createManualMatchFixture(manual: false);
    $game = $fx['games'][0];
    handTimeline($game, [4004, 4004, 4004, 4001, 4001, 4002, 4002]);
    $game->load('players', 'timeline');

    $parsed = ParseOpeningHand::run($game, 0, 1);

    expect(array_values($parsed['kept_hand']))->toBe([4004, 4004, 4004, 4001, 4001, 4002, 4002])
        ->and($parsed['local_mulligans'])->toBe(0);
});
