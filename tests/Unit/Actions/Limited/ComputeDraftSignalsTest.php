<?php

use App\Actions\Limited\Analytics\ComputeDraftSignals;
use App\Models\Card;
use Tests\TestCase;

uses(TestCase::class);

it('ranks colours by wheeled and seen twice counts', function () {
    $seenWheel = [
        1 => ['seen_count' => 2, 'wheeled' => true],
        2 => ['seen_count' => 2, 'wheeled' => true],
        3 => ['seen_count' => 3, 'wheeled' => false],
        4 => ['seen_count' => 1, 'wheeled' => false],
        5 => ['seen_count' => 2, 'wheeled' => true],
    ];
    $cards = collect([
        '1' => new Card(['mtgo_id' => '1', 'colors' => 'U']),
        '2' => new Card(['mtgo_id' => '2', 'colors' => 'U']),
        '3' => new Card(['mtgo_id' => '3', 'colors' => 'UG']),
        '4' => new Card(['mtgo_id' => '4', 'colors' => 'R']),
        '5' => new Card(['mtgo_id' => '5', 'colors' => '']),
    ]);

    $signals = ComputeDraftSignals::run($seenWheel, $cards);

    expect($signals)->toHaveCount(5)
        ->and($signals[0])->toMatchArray(['color' => 'U', 'wheeled' => 2, 'seen_twice' => 3, 'score' => 5, 'share' => 1.0])
        ->and($signals[1])->toMatchArray(['color' => 'G', 'wheeled' => 0, 'seen_twice' => 1, 'score' => 1])
        ->and(collect($signals)->firstWhere('color', 'R'))->toMatchArray(['wheeled' => 0, 'seen_twice' => 0, 'score' => 0, 'share' => 0.0]);
});
