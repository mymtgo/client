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
            ->where('hasMatches', true)
            ->has('layout', 7)
            ->where('layout.0.type', 'kpi_strip')
        );

    inertiaPartial(route('home'), 'Index', ['widget_default-kpi-strip'])
        ->assertOk()
        ->assertJsonPath('props.widget_default-kpi-strip.matchRecord.wins', 1)
        ->assertJsonPath('props.widget_default-kpi-strip.matchRecord.losses', 1)
        ->assertJsonPath('props.widget_default-kpi-strip.matchRecord.draws', 2)
        // 1 / 4 matches played.
        ->assertJsonPath('props.widget_default-kpi-strip.matchRecord.winrate', 25);
});
