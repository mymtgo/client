<?php

use App\Enums\MatchOutcome;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('counts draws as matches played in the home match winrate', function () {
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    foreach ([MatchOutcome::Win, MatchOutcome::Loss, MatchOutcome::Draw, MatchOutcome::Draw] as $outcome) {
        $match = MtgoMatch::factory()->create([
            'deck_version_id' => $version->id,
            'outcome' => $outcome,
            'started_at' => now()->subHour(),
        ]);
        Game::factory()->create(['match_id' => $match->id, 'won' => $outcome === MatchOutcome::Win]);
    }

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('matchRecord.wins', 1)
            ->where('matchRecord.losses', 1)
            ->where('matchRecord.draws', 2)
            // 1 / 4 matches played.
            ->where('matchRecord.winrate', 25)
        );
});
