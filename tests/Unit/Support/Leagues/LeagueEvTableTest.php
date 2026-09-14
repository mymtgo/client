<?php

use App\Support\Leagues\LeagueEvTable;

it('reads a completed run straight off the prize table', function () {
    expect(LeagueEvTable::netTixForRun('modern', 'complete', wins: 3, losses: 2))->toBe(2.62);
});

it('pays a dropped run as though the unplayed rounds were losses', function () {
    expect(LeagueEvTable::netTixForRun('modern', 'dropped', wins: 1, losses: 2))->toBe(-10.0);
});

it('has no figure for a run still being played', function () {
    expect(LeagueEvTable::netTixForRun('modern', 'active', wins: 1, losses: 1))->toBeNull();
});

it('has no figure for a completed run with no matches in it', function () {
    expect(LeagueEvTable::netTixForRun('modern', 'complete', wins: 0, losses: 0))->toBeNull();
});

it('has no figure for a format the prize table does not cover', function () {
    expect(LeagueEvTable::netTixForRun('limited', 'complete', wins: 3, losses: 0))->toBeNull();
});

it('knows what a league entry costs in the formats it covers', function () {
    expect(LeagueEvTable::entryCost('modern'))->toBe(10.0)
        ->and(LeagueEvTable::entryCost('limited'))->toBeNull();
});

it('finds where a run stops losing money, between the last brick and the first prize', function () {
    // 2-3 nets -5.00 and 3-2 nets +2.62, so the line is crossed part way between.
    expect(LeagueEvTable::breakEvenWins('modern', 5))->toBe(2.7)
        ->and(LeagueEvTable::breakEvenWins('limited', 3))->toBeNull();
});
