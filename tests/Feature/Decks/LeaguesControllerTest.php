<?php

use App\Enums\LeagueState;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns deck-scoped kpis', function () {
    $deck = Deck::factory()->create();
    $dv = DeckVersion::factory()->for($deck)->create();

    $league = League::factory()->for($dv)->create(['state' => LeagueState::Complete, 'format' => 'Modern']);
    foreach (range(1, 5) as $i) {
        MtgoMatch::factory()->create([
            'league_id' => $league->id,
            'deck_version_id' => $dv->id,
            'state' => MatchState::Complete,
            'outcome' => MatchOutcome::Win,
            'format' => 'Modern',
            'started_at' => now()->subMinutes($i),
            'ended_at' => now()->subMinutes($i),
        ]);
    }

    $this->get("/decks/{$deck->id}/leagues")
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p
            ->has('kpis.runs.total')
            ->has('kpis.trophies')
            ->etc()
        );
});
