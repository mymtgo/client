<?php

use App\Models\Account;
use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Account::flushCurrent();
});

it('forActiveAccount scope includes soft-deleted decks', function () {
    $live = Deck::factory()->create();
    $deleted = Deck::factory()->create();
    $deleted->delete();

    $ids = Deck::forActiveAccount()->pluck('id')->all();

    expect($ids)->toContain($live->id, $deleted->id);
});

it('forActiveAccount scope still scopes by account when one is active', function () {
    $active = Account::create(['username' => 'active-player', 'active' => true, 'tracked' => true]);
    $other = Account::create(['username' => 'other-player', 'active' => false, 'tracked' => true]);

    $mine = Deck::factory()->create(['account_id' => $active->id]);
    Deck::factory()->create(['account_id' => $other->id]);

    Account::flushCurrent();

    $ids = Deck::forActiveAccount()->pluck('id')->all();

    expect($ids)->toHaveCount(1)
        ->and($ids)->toContain($mine->id);
});
