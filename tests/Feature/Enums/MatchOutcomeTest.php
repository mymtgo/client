<?php

use App\Enums\MatchOutcome;
use App\Models\MtgoMatch;

it('determines win outcome', function () {
    expect(MtgoMatch::determineOutcome(2, 1))->toBe(MatchOutcome::Win);
});

it('determines loss outcome', function () {
    expect(MtgoMatch::determineOutcome(0, 2))->toBe(MatchOutcome::Loss);
});

it('determines draw outcome', function () {
    expect(MtgoMatch::determineOutcome(1, 1))->toBe(MatchOutcome::Draw);
});

it('determines unknown outcome when no games', function () {
    expect(MtgoMatch::determineOutcome(0, 0))->toBe(MatchOutcome::Unknown);
});

it('handles 1-0 concession correctly', function () {
    expect(MtgoMatch::determineOutcome(1, 0))->toBe(MatchOutcome::Win);
});
