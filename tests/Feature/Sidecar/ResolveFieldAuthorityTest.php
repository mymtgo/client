<?php

use App\Actions\Sidecar\ResolveFieldAuthority;
use App\Sidecar\SidecarAuthorityFlags;

it('defaults to username on and everything else off', function () {
    expect(SidecarAuthorityFlags::current())->toBe([
        'username' => true,
        'on_play' => false,
        'game_boundaries' => false,
        'game_result' => false,
        'match_result' => false,
        'match_deck' => false,
        'league_run' => false,
        'league_drop' => false,
    ]);
});

it('merges stored overrides and ignores unknown or non-boolean keys', function () {
    SidecarAuthorityFlags::applyRemote(['game_result' => true, 'bogus' => true, 'on_play' => 'yes']);

    expect(SidecarAuthorityFlags::current()['game_result'])->toBeTrue()
        ->and(SidecarAuthorityFlags::current()['on_play'])->toBeFalse()
        ->and(array_key_exists('bogus', SidecarAuthorityFlags::current()))->toBeFalse();
});

dataset('authority matrix', [
    'flag off' => [false, true,  true,  true,  'log'],
    'flag on, all good' => [true,  true,  true,  true,  'sidecar'],
    'flag on, coverage incomplete' => [true,  false, true,  true,  'log'],
    'flag on, unverified' => [true,  true,  false, true,  'log'],
    'flag on, cross check failed' => [true,  true,  true,  false, 'log'],
]);

it('chooses the source per the matrix', function (bool $flag, bool $coverage, bool $verified, bool $cross, string $expected) {
    $r = ResolveFieldAuthority::run('game_result', ['won' => true], ['won' => false], $flag, $coverage, $verified, $cross);

    expect($r->chosenSource)->toBe($expected)
        ->and($r->value)->toBe($expected === 'log' ? ['won' => true] : ['won' => false])
        ->and($r->disagree)->toBeTrue();
})->with('authority matrix');

it('fills a null log value from the sidecar only when every gate is open', function () {
    $r = ResolveFieldAuthority::run('on_play', null, 'Opp_Name', true, true, true, true);
    expect($r->value)->toBe('Opp_Name')->and($r->chosenSource)->toBe('sidecar')->and($r->disagree)->toBeFalse();

    $r = ResolveFieldAuthority::run('on_play', 'local.player', null, true, true, true, true);
    expect($r->value)->toBe('local.player')->and($r->chosenSource)->toBe('log')->and($r->disagree)->toBeFalse();
});

it('ignores the sidecar value for a null log value when the flag is off', function () {
    $r = ResolveFieldAuthority::run('on_play', null, 'Opp_Name', false, true, true, true);

    expect($r->value)->toBeNull()->and($r->chosenSource)->toBe('log')->and($r->disagree)->toBeFalse();
});

it('ignores the sidecar value for a null log value when a gate other than the flag is closed', function (bool $coverage, bool $verified, bool $cross) {
    $r = ResolveFieldAuthority::run('game_result', null, ['winner' => 'Opp_Name'], true, $coverage, $verified, $cross);

    expect($r->value)->toBeNull()->and($r->chosenSource)->toBe('log')->and($r->disagree)->toBeFalse();
})->with([
    'coverage incomplete' => [false, true, true],
    'unverified' => [true, false, true],
    'cross check failed' => [true, true, false],
]);

it('does not report disagreement for equal values in different key order', function () {
    $r = ResolveFieldAuthority::run('game_boundaries', ['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1], false, true, true, true);
    expect($r->disagree)->toBeFalse();
});
