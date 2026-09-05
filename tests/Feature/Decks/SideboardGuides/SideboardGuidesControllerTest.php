<?php

use App\Models\Archetype;
use App\Models\Deck;
use App\Models\SideboardGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists the deck guides with summaries and the deck sidebar props', function () {
    $deck = Deck::factory()->create();
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink']);
    SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id]);

    $this->get(route('decks.sideboard-guides.index', $deck))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('decks/SideboardGuides')
            ->where('currentPage', 'sideboard-guides')
            ->has('deck')
            ->has('guides', 1)
            ->where('guides.0.archetypeName', 'Esper Blink')
            ->where('guides.0.matchRecord', null)
        );
});

it('defers the archetype options', function () {
    $deck = Deck::factory()->create();

    $response = $this->get(route('decks.sideboard-guides.index', $deck))->assertOk();

    $response->assertInertia(fn ($page) => $page->missing('archetypes'));

    expect($response->viewData('page')['deferredProps']['default'] ?? [])->toContain('archetypes');
});

it('renders for a soft-deleted deck', function () {
    $deck = Deck::factory()->create();
    $deck->delete();

    $this->get(route('decks.sideboard-guides.index', $deck))->assertOk();
});
