<?php

use App\Actions\Util\TimeframeRange;

it('ends the previous window one second before the current one starts', function () {
    [$start] = TimeframeRange::run('monthly');

    [, $previousEnd] = TimeframeRange::previous('monthly', $start);

    expect($previousEnd->timestamp)->toBe($start->timestamp - 1);
});

it('gives the previous window the same span as the one it precedes', function () {
    [$start, $end] = TimeframeRange::run('week');

    [$previousStart, $previousEnd] = TimeframeRange::previous('week', $start);

    expect($previousEnd->timestamp - $previousStart->timestamp)
        ->toBe($end->timestamp - $start->timestamp);
});

it('walks a year window back to the year before', function () {
    [$start] = TimeframeRange::run('year');

    [$previousStart, $previousEnd] = TimeframeRange::previous('year', $start);

    expect($previousStart->year)->toBe($start->year - 1)
        ->and($previousEnd->year)->toBe($start->year - 1);
});
