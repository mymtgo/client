<?php

use App\Actions\Util\TimeframeRange;
use App\Facades\AppSettings;

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

it('bounds a custom range on whole local days', function () {
    AppSettings::setSystemTimezone('America/Los_Angeles');

    [$start, $end] = TimeframeRange::run('2026-09-01..2026-09-15');

    expect($start->toIso8601String())->toBe('2026-09-01T07:00:00+00:00')
        ->and($end->toIso8601String())->toBe('2026-09-16T06:59:59+00:00');
});

it('reads a single-day custom range as that whole day', function () {
    AppSettings::setSystemTimezone('UTC');

    [$start, $end] = TimeframeRange::run('2026-09-10..2026-09-10');

    expect($start->toDateTimeString())->toBe('2026-09-10 00:00:00')
        ->and($end->toDateTimeString())->toBe('2026-09-10 23:59:59');
});

it('swaps a custom range given end first', function () {
    AppSettings::setSystemTimezone('UTC');

    [$start, $end] = TimeframeRange::run('2026-09-15..2026-09-01');

    expect($start->toDateString())->toBe('2026-09-01')
        ->and($end->toDateString())->toBe('2026-09-15');
});

it('falls back to all time for a malformed custom range', function (string $timeframe) {
    expect(TimeframeRange::run($timeframe))->toEqual(TimeframeRange::run('alltime'));
})->with([
    'not dates' => ['foo..bar'],
    'impossible date' => ['2026-02-31..2026-03-01'],
    'one side missing' => ['2026-09-01..'],
    'no separator' => ['2026-09-01'],
]);

it('gives a custom range a previous window of the same length', function () {
    AppSettings::setSystemTimezone('UTC');

    [$start, $end] = TimeframeRange::run('2026-09-11..2026-09-20');
    [$previousStart, $previousEnd] = TimeframeRange::previous('2026-09-11..2026-09-20', $start);

    expect($previousStart->toDateTimeString())->toBe('2026-09-01 00:00:00')
        ->and($previousEnd->toDateTimeString())->toBe('2026-09-10 23:59:59')
        ->and($previousEnd->timestamp - $previousStart->timestamp)->toBe($end->timestamp - $start->timestamp);
});
