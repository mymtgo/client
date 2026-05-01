<?php

use App\Support\Leagues\LeagueEvTable;

it('returns net tix for modern 5-0', function () {
    expect(LeagueEvTable::netTix('Modern', 5, 0))->toBe(29.02);
});

it('returns net tix for modern 4-1', function () {
    expect(LeagueEvTable::netTix('Modern', 4, 1))->toBe(12.70);
});

it('returns net tix for modern 3-2', function () {
    expect(LeagueEvTable::netTix('Modern', 3, 2))->toBe(2.62);
});

it('returns net tix for modern 2-3', function () {
    expect(LeagueEvTable::netTix('Modern', 2, 3))->toBe(-5.00);
});

it('returns net tix for modern 1-4', function () {
    expect(LeagueEvTable::netTix('Modern', 1, 4))->toBe(-10.00);
});

it('returns net tix for modern 0-5', function () {
    expect(LeagueEvTable::netTix('Modern', 0, 5))->toBe(-10.00);
});

it('returns null for unknown format', function () {
    expect(LeagueEvTable::netTix('Frontier', 5, 0))->toBeNull();
});

it('returns null for unknown score', function () {
    expect(LeagueEvTable::netTix('Modern', 6, 0))->toBeNull();
});

it('matches format case-insensitively', function () {
    expect(LeagueEvTable::netTix('modern', 5, 0))->toBe(29.02);
    expect(LeagueEvTable::netTix('MODERN', 5, 0))->toBe(29.02);
});
