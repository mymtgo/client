<?php

use App\Managers\MtgoManager;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prefers a known candidate over the in-memory username', function () {
    // The in-memory name is pinned on a singleton and can be stale from a
    // previous match; the candidates are this match's actual players.
    $manager = new MtgoManager;
    $manager->setUsername('MemoryPlayer');

    Account::create(['username' => 'DBPlayer', 'active' => true, 'tracked' => true]);

    expect($manager->resolveUsername(['DBPlayer']))->toBe('DBPlayer');
});

it('returns in-memory username when no candidate is a known account', function () {
    $manager = new MtgoManager;
    $manager->setUsername('MemoryPlayer');

    Account::create(['username' => 'DBPlayer', 'active' => true, 'tracked' => true]);

    expect($manager->resolveUsername(['Opponent']))->toBe('MemoryPlayer');
});

it('returns active account username when no in-memory username', function () {
    $manager = new MtgoManager;

    Account::create(['username' => 'ActivePlayer', 'active' => true, 'tracked' => true]);

    expect($manager->resolveUsername())->toBe('ActivePlayer');
});

it('always returns active account even when candidates differ', function () {
    $manager = new MtgoManager;

    Account::create(['username' => 'ActivePlayer', 'active' => true, 'tracked' => true]);

    // Active account is always returned first, regardless of candidates
    expect($manager->resolveUsername(['SomeOpponent']))->toBe('ActivePlayer');
});

it('returns null when no accounts exist and no candidates given', function () {
    $manager = new MtgoManager;

    expect($manager->resolveUsername(['UnknownPlayer1', 'UnknownPlayer2']))->toBeNull();
});

it('returns null when no username available and no candidates given', function () {
    $manager = new MtgoManager;

    expect($manager->resolveUsername())->toBeNull();
});

it('prefers a known account among the candidates over the active account', function () {
    $manager = new MtgoManager;

    // Active account first: Account::booted() auto-activates the first row
    // saved when none is active, which would make PlayerA active by accident.
    Account::create(['username' => 'PlayerB', 'active' => true, 'tracked' => true]);
    Account::create(['username' => 'PlayerA', 'active' => false, 'tracked' => true]);

    expect($manager->resolveUsername(['PlayerA', 'Opponent']))->toBe('PlayerA');
});

it('falls back to the active account when the candidates name more than one known account', function () {
    $manager = new MtgoManager;

    // Active account first: Account::booted() auto-activates the first row
    // saved when none is active, which would make PlayerA active by accident.
    Account::create(['username' => 'PlayerB', 'active' => true, 'tracked' => true]);
    Account::create(['username' => 'PlayerA', 'active' => false, 'tracked' => true]);

    expect($manager->resolveUsername(['PlayerA', 'PlayerB']))->toBe('PlayerB');
});
