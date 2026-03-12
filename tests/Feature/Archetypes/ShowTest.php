<?php

use App\Models\Archetype;
use App\Models\Card;
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

it('includes cards when decklist is downloaded', function () {
    $archetype = Archetype::factory()->withDecklist()->create();
    $card = Card::factory()->create(['oracle_id' => 'test-oracle', 'type' => 'Instant']);
    $archetype->cards()->attach($card->id, ['quantity' => 4, 'sideboard' => false]);

    $response = $this->get("/archetypes/{$archetype->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('archetypes/Show')
        ->where('detail.archetype.hasDecklist', true)
        ->has('detail.cards', 1)
    );
});

it('returns null cards when decklist not downloaded', function () {
    $archetype = Archetype::factory()->create();

    $response = $this->get("/archetypes/{$archetype->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('archetypes/Show')
        ->where('detail.archetype.hasDecklist', false)
        ->where('detail.cards', null)
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
