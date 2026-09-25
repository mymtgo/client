<?php

use App\Sidecar\SidecarAuthorityFlags;

it('defaults the league and deck fields to on for the live test', function () {
    expect(SidecarAuthorityFlags::current())
        ->toMatchArray(['match_deck' => true, 'league_run' => true, 'league_drop' => true]);
});

it('can still switch the league and deck fields off', function () {
    SidecarAuthorityFlags::applyRemote(['league_run' => false, 'match_deck' => false, 'league_drop' => false]);

    expect(SidecarAuthorityFlags::isOn('league_run'))->toBeFalse()
        ->and(SidecarAuthorityFlags::isOn('match_deck'))->toBeFalse()
        ->and(SidecarAuthorityFlags::isOn('league_drop'))->toBeFalse();
});

it('accepts overrides for the league and deck fields', function () {
    SidecarAuthorityFlags::applyRemote(['league_run' => true, 'match_deck' => true, 'league_drop' => true]);

    expect(SidecarAuthorityFlags::isOn('league_run'))->toBeTrue()
        ->and(SidecarAuthorityFlags::isOn('match_deck'))->toBeTrue()
        ->and(SidecarAuthorityFlags::isOn('league_drop'))->toBeTrue();
});
