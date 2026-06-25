<?php

use App\Models\Account;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('includes opponentName in the match prop when opponent is set', function () {
    $opponent = Opponent::factory()->create(['username' => 'ProPlayer99']);
    $account = Account::factory()->create();

    $match = MtgoMatch::factory()->create([
        'opponent_id' => $opponent->id,
        'account_id' => $account->id,
    ]);

    $this->get(route('matches.show', $match->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('matches/Show')
            ->where('match.opponentName', 'ProPlayer99')
        );
});

it('includes null opponentName when no opponent is set', function () {
    $match = MtgoMatch::factory()->create([
        'opponent_id' => null,
    ]);

    $this->get(route('matches.show', $match->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('matches/Show')
            ->where('match.opponentName', null)
        );
});
