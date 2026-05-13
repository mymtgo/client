<?php

use App\Actions\Logs\Testing\SanitizeMtgoLog;

it('substitutes a known username with a placeholder', function () {
    expect(SanitizeMtgoLog::run('@Phyuma rolled a 1.', usernameMap: ['hyuma' => 'opp_a']))
        ->toBe('@Popp_a rolled a 1.');
});

it('remaps match tokens deterministically', function () {
    $tokenMap = ['4e8ed942-a897-458f-af1f-2e27aedd65dd' => '00000000-0000-0000-0000-000000000001'];

    expect(SanitizeMtgoLog::run('"MatchToken":"4e8ed942-a897-458f-af1f-2e27aedd65dd"', matchTokenMap: $tokenMap))
        ->toContain('00000000-0000-0000-0000-000000000001')
        ->and(SanitizeMtgoLog::run('"MatchToken":"4e8ed942-a897-458f-af1f-2e27aedd65dd"', matchTokenMap: $tokenMap))
        ->not->toContain('4e8ed942');
});
