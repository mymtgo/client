<?php

use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows tournaments on the deck Tournaments page', function () {
    $deck = Deck::factory()->create(['name' => 'Test Deck']);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $tournament = Tournament::factory()->create([
        'deck_version_id' => $version->id,
        'name' => 'Legacy Challenge 32',
        'format' => 'CLEGACY',
        'started_at' => now()->subHour(),
    ]);
    MtgoMatch::factory()->count(2)->create([
        'deck_version_id' => $version->id,
        'tournament_id' => $tournament->id,
        'outcome' => 'win',
        'started_at' => now()->subHour(),
    ]);
    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'tournament_id' => $tournament->id,
        'outcome' => 'loss',
        'started_at' => now()->subHour(),
    ]);

    $this->get(route('decks.tournaments', ['deck' => $deck->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('decks/Tournaments')
            ->has('tournaments', 1)
            ->where('tournaments.0.name', 'Legacy Challenge 32')
            ->where('tournaments.0.format', 'Legacy')
            ->where('tournaments.0.wins', 2)
            ->where('tournaments.0.losses', 1)
        );
});

it('shows empty state when deck has no tournaments', function () {
    $deck = Deck::factory()->create();

    $this->get(route('decks.tournaments', ['deck' => $deck->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('decks/Tournaments')
            ->has('tournaments', 0)
        );
});
