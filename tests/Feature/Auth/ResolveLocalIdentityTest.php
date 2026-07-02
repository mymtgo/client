<?php

use App\Actions\Auth\ResolveLocalIdentity;
use App\Facades\Mtgo;
use App\Models\AppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bindAccount(array $overrides = []): AppAccount
{
    return AppAccount::create(array_merge([
        'user_id' => 1,
        'mtgo_player_id' => 147160,
        'mtgo_username' => 'Pro_MTG',
        'active' => true,
    ], $overrides));
}

it('returns null when no account is bound', function () {
    Mtgo::setUsername('Pro_MTG');

    expect(app(ResolveLocalIdentity::class)->run())->toBeNull();
});

it('returns null when the log username cannot be read', function () {
    bindAccount();

    expect(app(ResolveLocalIdentity::class)->run())->toBeNull();
});

it('returns null when the log username differs from the bound account (mismatch guard)', function () {
    bindAccount();
    Mtgo::setUsername('SomeoneElse');

    expect(app(ResolveLocalIdentity::class)->run())->toBeNull();
});

it('ignores an inactive binding', function () {
    bindAccount(['active' => false]);
    Mtgo::setUsername('Pro_MTG');

    expect(app(ResolveLocalIdentity::class)->run())->toBeNull();
});

it('resolves identity when the log username matches the binding', function () {
    bindAccount();
    Mtgo::setUsername('Pro_MTG');

    $identity = app(ResolveLocalIdentity::class)->run();

    expect($identity->mtgoPlayerId)->toBe(147160);
    expect($identity->mtgoUsername)->toBe('Pro_MTG');
});
