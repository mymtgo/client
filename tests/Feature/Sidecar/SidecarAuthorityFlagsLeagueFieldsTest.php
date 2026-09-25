<?php

use App\Sidecar\SidecarAuthorityFlags;

it('defaults the league and deck fields to off', function () {
    expect(SidecarAuthorityFlags::current())
        ->toMatchArray(['match_deck' => false, 'league_run' => false, 'league_drop' => false]);
});

it('accepts overrides for the league and deck fields', function () {
    SidecarAuthorityFlags::applyRemote(['league_run' => true, 'match_deck' => true, 'league_drop' => true]);

    expect(SidecarAuthorityFlags::isOn('league_run'))->toBeTrue()
        ->and(SidecarAuthorityFlags::isOn('match_deck'))->toBeTrue()
        ->and(SidecarAuthorityFlags::isOn('league_drop'))->toBeTrue();
});
