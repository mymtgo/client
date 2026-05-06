<?php

use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders deck tournaments inertia page with tournaments for that deck', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $tournament = Tournament::factory()->create([
        'name' => 'Legacy Challenge 32',
        'started_at' => now()->subHours(2),
    ]);
    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'tournament_id' => $tournament->id,
        'outcome' => 'win',
        'started_at' => now()->subHours(2),
    ]);

    $this->get(route('decks.tournaments', ['deck' => $deck->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('decks/Tournaments')
            ->has('tournaments', 1)
            ->where('tournaments.0.name', 'Legacy Challenge 32')
            ->where('tournaments.0.wins', 1)
        );
});

it('does not include tournaments belonging to other decks', function () {
    $deck = Deck::factory()->create();
    $otherDeck = Deck::factory()->create();
    $otherVersion = DeckVersion::factory()->create(['deck_id' => $otherDeck->id]);
    $otherTournament = Tournament::factory()->create();
    MtgoMatch::factory()->create([
        'deck_version_id' => $otherVersion->id,
        'tournament_id' => $otherTournament->id,
    ]);

    $this->get(route('decks.tournaments', ['deck' => $deck->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('tournaments', 0));
});
