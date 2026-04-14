<?php

use App\Managers\MtgoManager;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns in-memory username first', function () {
    $manager = new MtgoManager;
    $manager->setUsername('MemoryPlayer');

    Account::create(['username' => 'DBPlayer', 'active' => true, 'tracked' => true]);

    expect($manager->resolveUsername(['DBPlayer']))->toBe('MemoryPlayer');
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
