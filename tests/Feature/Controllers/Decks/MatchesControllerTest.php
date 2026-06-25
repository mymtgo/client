<?php

use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('includes opponentName in matches when opponent is set', function () {
    $opponent = Opponent::factory()->create(['username' => 'EnemyPlayer42']);

    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'opponent_id' => $opponent->id,
        'started_at' => now()->subHour(),
    ]);

    $this->get(route('decks.matches', $deck->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('decks/Matches')
            ->has('matches.data', 1)
            ->where('matches.data.0.opponentName', 'EnemyPlayer42')
        );
});

it('includes null opponentName in matches when no opponent is set', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'opponent_id' => null,
        'started_at' => now()->subHour(),
    ]);

    $this->get(route('decks.matches', $deck->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('decks/Matches')
            ->has('matches.data', 1)
            ->where('matches.data.0.opponentName', null)
        );
});
