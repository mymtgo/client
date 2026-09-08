<?php

use App\Enums\MatchState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('provides manual match form options scoped to the deck', function () {
    $deck = Deck::factory()->create(['format' => 'CMODERN']);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $mine = League::factory()->create(['deck_version_id' => $version->id]);
    League::factory()->create(['deck_version_id' => DeckVersion::factory()->create()->id]);

    $this->get("/decks/{$deck->id}/matches")
        ->assertInertia(fn ($page) => $page
            ->where('manualMatchDeck.id', $deck->id)
            ->where('manualMatchDeck.formatCode', 'CMODERN')
            ->where('manualMatchLeagues', fn ($leagues) => collect($leagues)->pluck('id')->all() === [$mine->id]));
});

it('filters the match list to manual matches', function () {
    $deck = Deck::factory()->create(['format' => 'CMODERN']);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'state' => MatchState::Complete, 'manual' => false]);
    $manual = MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'state' => MatchState::Complete, 'manual' => true]);

    $this->get("/decks/{$deck->id}/matches?filter_type=manual")
        ->assertInertia(fn ($page) => $page
            ->where('matches.total', 1)
            ->where('matches.data.0.id', $manual->id));
});
