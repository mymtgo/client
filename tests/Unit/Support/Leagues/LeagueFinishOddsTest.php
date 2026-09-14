<?php

use App\Support\Leagues\LeagueFinishOdds;

it('needs the lowest paying finish to count as the prize threshold', function () {
    $odds = LeagueFinishOdds::run('modern', wins: 1, losses: 1, rounds: 5, winProbability: 0.5);

    expect($odds['prizeWins'])->toBe(3)
        ->and($odds['winsForPrize'])->toBe(2)
        ->and($odds['roundsLeft'])->toBe(3);
});

it('gives even odds of two wins in three rounds at a coin flip', function () {
    $odds = LeagueFinishOdds::run('modern', wins: 1, losses: 1, rounds: 5, winProbability: 0.5);

    expect($odds['chanceOfPrize'])->toBe(50);
});

it('reports a finished run as settled rather than a probability', function () {
    $odds = LeagueFinishOdds::run('modern', wins: 3, losses: 2, rounds: 5, winProbability: 0.5);

    expect($odds['roundsLeft'])->toBe(0)
        ->and($odds['winsForPrize'])->toBe(0)
        ->and($odds['chanceOfPrize'])->toBe(100)
        ->and($odds['expectedTix'])->toBe(2.62);
});

it('weights expected tix by every reachable finish', function () {
    $odds = LeagueFinishOdds::run('modern', wins: 4, losses: 0, rounds: 5, winProbability: 0.5);

    // One round left: half the time 5-0 at 29.02, half the time 4-1 at 12.70.
    expect($odds['expectedTix'])->toBe(20.86);
});

it('has no tix figure for a format the prize table does not cover', function () {
    $odds = LeagueFinishOdds::run('limited', wins: 1, losses: 1, rounds: 3, winProbability: 0.5);

    expect($odds['expectedTix'])->toBeNull()
        ->and($odds['prizeWins'])->toBeNull()
        ->and($odds['chanceOfPrize'])->toBeNull();
});
