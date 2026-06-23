<?php

use App\Models\Account;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('belongs to an account', function () {
    $account = Account::factory()->create();
    $match = MtgoMatch::factory()->create(['account_id' => $account->id]);

    expect($match->account)->not->toBeNull();
    expect($match->account->id)->toBe($account->id);
});

it('belongs to an opponent', function () {
    $opponent = Opponent::factory()->create();
    $match = MtgoMatch::factory()->create(['opponent_id' => $opponent->id]);

    expect($match->opponent->id)->toBe($opponent->id);
});

it('allows null account and opponent', function () {
    $match = MtgoMatch::factory()->create();

    expect($match->account)->toBeNull();
    expect($match->opponent)->toBeNull();
});
