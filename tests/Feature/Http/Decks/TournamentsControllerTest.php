<?php

use App\Enums\TournamentState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use App\Models\TournamentStanding;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the deck tournaments tab', function () {
    $deck = Deck::factory()->create();

    $response = $this->get("/decks/{$deck->id}/tournaments");

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->component('decks/Tournaments'));
});

it('returns tournament KPIs for a deck', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->for($deck)->create();

    $completed = Tournament::factory()->create([
        'participated' => true,
        'state' => TournamentState::Completed,
        'max_rounds' => 7,
    ]);

    TournamentStanding::factory()->create([
        'tournament_id' => $completed->id,
        'round' => 7,
        'login_id' => 964394,
        'rank' => 6,
        'is_local' => true,
    ]);

    MtgoMatch::factory()->create([
        'tournament_id' => $completed->id,
        'tournament_round' => 1,
        'deck_version_id' => $version->id,
    ]);

    $response = $this->get("/decks/{$deck->id}/tournaments");

    $response->assertInertia(fn ($page) => $page
        ->component('decks/Tournaments')
        ->where('kpis.tournaments_played', 1)
        ->where('kpis.best_finish', 6)
        ->where('kpis.top_8', 1)
        ->where('kpis.top_16', 1)
    );
});

it('ignores in-progress tournaments for best_finish', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->for($deck)->create();

    $inProgress = Tournament::factory()->create([
        'participated' => true,
        'state' => TournamentState::RoundInProgress,
    ]);

    TournamentStanding::factory()->create([
        'tournament_id' => $inProgress->id,
        'round' => 3,
        'login_id' => 964394,
        'rank' => 2,
        'is_local' => true,
    ]);

    MtgoMatch::factory()->create([
        'tournament_id' => $inProgress->id,
        'deck_version_id' => $version->id,
    ]);

    $response = $this->get("/decks/{$deck->id}/tournaments");

    $response->assertInertia(fn ($page) => $page
        ->where('kpis.tournaments_played', 1)
        ->where('kpis.best_finish', null)
        ->where('kpis.top_8', 0)
        ->where('kpis.top_16', 0)
    );
});
