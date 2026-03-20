<?php

use App\Enums\MatchOutcome;
use App\Models\MtgoMatch;

it('determines correct outcome from win/loss counts', function () {
    expect(MtgoMatch::determineOutcome(2, 1))->toBe(MatchOutcome::Win);
    expect(MtgoMatch::determineOutcome(1, 2))->toBe(MatchOutcome::Loss);
    expect(MtgoMatch::determineOutcome(1, 1))->toBe(MatchOutcome::Draw);
    expect(MtgoMatch::determineOutcome(0, 0))->toBe(MatchOutcome::Unknown);
});
