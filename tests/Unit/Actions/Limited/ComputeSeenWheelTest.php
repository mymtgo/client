<?php

use App\Actions\Limited\Analytics\ComputeSeenWheel;
use App\Models\DraftPick;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Build in-memory picks: [ordinal => [pack_id, cards_available, picked]].
 */
function picksFrom(array $rows): Collection
{
    return collect($rows)->map(fn (array $r, int $ordinal) => new DraftPick([
        'ordinal' => $ordinal,
        'pack_id' => $r[0],
        'cards_available' => $r[1],
        'picked_catalog_id' => $r[2],
    ]))->values();
}

it('derives seen, wheel and passed for an 8 seat pod', function () {
    $picks = picksFrom([
        1 => [500, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14], 8],
        2 => [501, [20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32], 20],
        3 => [502, [40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51], 40],
        4 => [503, [60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 70], 60],
        5 => [504, [80, 81, 82, 83, 84, 85, 86, 87, 88, 89], 80],
        6 => [505, [90, 91, 92, 93, 94, 95, 96, 97, 98], 90],
        7 => [506, [100, 101, 102, 103, 104, 105, 106, 107], 100],
        8 => [507, [110, 111, 112, 113, 114, 115, 116], 110],
        9 => [500, [1, 2, 5, 6, 11, 14], 1],
        10 => [501, [21, 22, 23, 24, 25], 21],
    ]);

    $result = ComputeSeenWheel::run($picks, 8);

    expect($result[1])->toMatchArray(['seen_count' => 2, 'first_seen_ordinal' => 1, 'wheeled' => true, 'wheeled_to_me' => true, 'picked_count' => 1, 'passed_count' => 1])
        ->and($result[2])->toMatchArray(['seen_count' => 2, 'wheeled' => true, 'wheeled_to_me' => false, 'picked_count' => 0, 'passed_count' => 2])
        ->and($result[3])->toMatchArray(['seen_count' => 1, 'wheeled' => false, 'passed_count' => 1])
        ->and($result[8])->toMatchArray(['seen_count' => 1, 'wheeled' => false, 'picked_count' => 1, 'passed_count' => 0])
        ->and($result[21])->toMatchArray(['seen_count' => 2, 'wheeled' => true, 'wheeled_to_me' => true]);
});

it('uses seat count for the wheel distance in a 6 seat pod', function () {
    $picks = picksFrom([
        1 => [900, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14], 1],
        2 => [901, [20, 21, 22], 20],
        3 => [902, [30, 31], 30],
        4 => [903, [40], 40],
        5 => [904, [50], 50],
        6 => [905, [60], 60],
        7 => [900, [2, 3, 4, 5, 6, 7, 8, 9], 2],
    ]);

    $result = ComputeSeenWheel::run($picks, 6);

    expect($result[2]['wheeled'])->toBeTrue()
        ->and($result[2]['wheeled_to_me'])->toBeTrue()
        ->and($result[14]['wheeled'])->toBeFalse();
});

it('does not treat a different pack at the wheel distance as a wheel', function () {
    $picks = picksFrom([
        1 => [500, [1, 2, 3], 1],
        9 => [999, [2, 3], 2],
    ]);

    expect(ComputeSeenWheel::run($picks, 8)[2]['wheeled'])->toBeFalse();
});

it('describes the wheel view for a pick', function () {
    $picks = picksFrom([
        1 => [500, [1, 2, 3, 4], 4],
        9 => [500, [1, 3], 1],
    ]);

    $view = ComputeSeenWheel::wheelForPick($picks, $picks->first(), 8);

    expect($view)->toBe(['return_ordinal' => 9, 'survived' => [1, 3], 'taken' => [2]]);
    expect(ComputeSeenWheel::wheelForPick($picks, $picks->last(), 8))->toBeNull();
});
