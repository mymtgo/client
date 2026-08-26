<?php

use App\Actions\Limited\Analytics\ComputePickTimings;
use App\Models\DraftPick;
use Carbon\Carbon;
use Tests\TestCase;

uses(TestCase::class);

it('computes elapsed, margin and indecision for a pick', function () {
    $pick = new DraftPick([
        'ordinal' => 1, 'pack_number' => 1,
        'shown_at' => Carbon::parse('2026-08-22 11:00:00'),
        'picked_at' => Carbon::parse('2026-08-22 11:00:26'),
        'deadline_at' => Carbon::parse('2026-08-22 11:01:09'),
        'reservations' => [['catalog_id' => 1, 'at' => 'a'], ['catalog_id' => 2, 'at' => 'b']],
    ]);

    expect(ComputePickTimings::forPick($pick))->toBe([
        'elapsed_seconds' => 26, 'margin_seconds' => 43, 'reservation_count' => 2, 'indecisive' => true,
    ]);
});

it('returns nulls when timestamps are missing', function () {
    $pick = new DraftPick(['ordinal' => 1, 'pack_number' => 1, 'reservations' => []]);

    expect(ComputePickTimings::forPick($pick))->toBe([
        'elapsed_seconds' => null, 'margin_seconds' => null, 'reservation_count' => 0, 'indecisive' => false,
    ]);
});

it('summarises a draft', function () {
    $mk = fn (int $ordinal, int $pack, int $secs, int $res) => new DraftPick([
        'ordinal' => $ordinal, 'pack_number' => $pack,
        'shown_at' => Carbon::parse('2026-08-22 11:00:00'),
        'picked_at' => Carbon::parse('2026-08-22 11:00:00')->addSeconds($secs),
        'deadline_at' => Carbon::parse('2026-08-22 11:00:00')->addSeconds(60),
        'reservations' => array_fill(0, $res, ['catalog_id' => 1, 'at' => 'x']),
    ]);
    $picks = collect([$mk(1, 1, 30, 2), $mk(2, 1, 20, 1), $mk(15, 2, 10, 0), $mk(29, 3, 4, 3)]);

    expect(ComputePickTimings::summary($picks))->toBe([
        'avg_seconds' => 16, 'avg_margin_seconds' => 44, 'indecisive_count' => 2, 'fastest_pack' => 3, 'slowest_pack' => 1,
    ]);
});

it('reports a null average margin when no pick has a deadline', function () {
    $picks = collect([
        new DraftPick([
            'ordinal' => 1, 'pack_number' => 1, 'reservations' => [],
            'shown_at' => Carbon::parse('2026-08-22 11:00:00'),
            'picked_at' => Carbon::parse('2026-08-22 11:00:12'),
        ]),
    ]);

    expect(ComputePickTimings::summary($picks)['avg_margin_seconds'])->toBeNull();
});
