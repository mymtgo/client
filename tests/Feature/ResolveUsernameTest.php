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

it('falls back to candidate match against inactive account', function () {
    $manager = new MtgoManager;

    Account::create(['username' => 'InactivePlayer', 'active' => false, 'tracked' => true]);

    expect($manager->resolveUsername(['InactivePlayer', 'Opponent']))->toBe('InactivePlayer');
});

it('returns null when no candidates match any account', function () {
    $manager = new MtgoManager;

    Account::create(['username' => 'KnownPlayer', 'active' => false, 'tracked' => true]);

    expect($manager->resolveUsername(['UnknownPlayer1', 'UnknownPlayer2']))->toBeNull();
});

it('returns null when no username available and no candidates given', function () {
    $manager = new MtgoManager;

    expect($manager->resolveUsername())->toBeNull();
});
