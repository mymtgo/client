<?php

use App\Models\Account;
use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('adopts decks that arrived before the account was known', function () {
    $orphan = Deck::factory()->create(['account_id' => null]);

    $account = Account::registerAndActivate('anticloser');

    expect($orphan->fresh()->account_id)->toBe($account->id);
});

it('does not re-home orphans onto a second account', function () {
    $first = Account::registerAndActivate('anticloser');

    $orphan = Deck::factory()->create(['account_id' => null]);

    Account::registerAndActivate('someone-else');

    expect($orphan->fresh()->account_id)->toBeNull()
        ->and(Deck::where('account_id', $first->id)->count())->toBe(0);
});
