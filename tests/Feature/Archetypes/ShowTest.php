<?php

use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows archetype detail page', function () {
    $archetype = Archetype::factory()->create();

    $response = $this->get("/archetypes/{$archetype->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('archetypes/Show')
        ->has('archetypes')
    );
});

it('includes decks when archetype has decks', function () {
    $archetype = Archetype::factory()->withDecklist()->create();
    $deck = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 5]);
    $card = Card::factory()->create(['oracle_id' => 'test-oracle', 'type' => 'Instant']);
    $deck->cards()->attach($card->id, ['quantity' => 4, 'sideboard' => false]);

    $response = $this->get("/archetypes/{$archetype->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('archetypes/Show')
        ->where('detail.archetype.hasDecklist', true)
        ->has('detail.decks', 1)
        ->has('detail.decks.0.cards', 1)
    );
});

it('returns empty decks array when archetype has no decks', function () {
    $archetype = Archetype::factory()->create();

    $response = $this->get("/archetypes/{$archetype->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('archetypes/Show')
        ->where('detail.archetype.hasDecklist', false)
        ->where('detail.decks', [])
    );
});

it('detects stale decklist', function () {
    $archetype = Archetype::factory()->staleDecklist()->create();

    $response = $this->get("/archetypes/{$archetype->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('archetypes/Show')
        ->where('detail.isStale', true)
    );
});

it('preserves sidebar filters on show page', function () {
    Archetype::factory()->count(5)->create(['format' => 'modern']);
    $archetype = Archetype::factory()->create(['format' => 'modern']);

    $response = $this->get("/archetypes/{$archetype->id}?format=modern&search=&page=1");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('filters.format', 'modern')
    );
});

it('includes per-variant facing winrate on each deck and orders by matches played', function () {
    $archetype = Archetype::factory()->create(['decklist_downloaded_at' => now()]);

    // Variant with matches has the lower seen_count; should still appear first.
    $variantWithMatches = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 1]);
    $variantEmpty = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 10]);

    foreach ([true, false] as $localWon) {
        $match = MtgoMatch::create([
            'token' => fake()->uuid(),
            'mtgo_id' => fake()->unique()->numerify('######'),
            'format' => 'modern',
            'match_type' => 'league',
            'state' => 'complete',
            'outcome' => $localWon ? 'win' : 'loss',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        MatchArchetype::create([
            'archetype_id' => $archetype->id,
            'archetype_deck_id' => $variantWithMatches->id,
            'mtgo_match_id' => $match->id,
            'is_opponent' => true,
            'confidence' => 0.9,
        ]);
    }

    $response = $this->get(route('archetypes.show', $archetype));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('archetypes/Show')
        ->has('detail.decks', 2)
        ->where('detail.decks.0.id', $variantWithMatches->id)
        ->where('detail.decks.0.facingWinrate', 50)
        ->where('detail.decks.0.wins', 1)
        ->where('detail.decks.0.losses', 1)
        ->where('detail.decks.1.id', $variantEmpty->id)
        ->where('detail.decks.1.facingWinrate', null)
        ->where('detail.decks.1.wins', 0)
        ->where('detail.decks.1.losses', 0)
        ->missing('detail.playingWinrate')
        ->missing('detail.facingWinrate')
    );
});

it('returns multiple decks ordered by seen_count desc', function () {
    $archetype = Archetype::factory()->create(['decklist_downloaded_at' => now()]);

    $deckLow = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 2]);
    $deckHigh = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 10]);
    $card = Card::factory()->create();
    $deckHigh->cards()->attach($card->id, ['quantity' => 4, 'sideboard' => false]);
    $deckLow->cards()->attach($card->id, ['quantity' => 2, 'sideboard' => false]);

    $response = $this->get(route('archetypes.show', $archetype));

    $response->assertInertia(fn ($page) => $page
        ->component('archetypes/Show')
        ->has('detail.decks', 2)
        ->where('detail.decks.0.id', $deckHigh->id)
        ->where('detail.decks.0.seenCount', 10)
        ->where('detail.decks.1.id', $deckLow->id)
    );
});
