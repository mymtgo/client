<?php

use App\Models\Archetype;
use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

it('returns local source by default', function () {
    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['archetype_id' => $archetype->id, 'format' => 'Standard']);

    $this->get(route('decks.card-stats', $deck))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cardStats.source', 'local')
            ->where('cardStats.externalError', false)
        );
});

it('returns external source when toggle on and archetype set', function () {
    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['archetype_id' => $archetype->id, 'format' => 'Standard']);

    Http::fake(['*' => Http::response([
        'stats' => [],
        'archetype_winrate' => ['games' => 100, 'wins' => 55, 'rate' => 0.55],
        'opponents' => [],
        'refreshed_at' => '2026-05-22T00:00:00Z',
    ], 200)]);

    $this->get(route('decks.card-stats', $deck).'?card_stats_source=external')
        ->assertInertia(fn (Assert $page) => $page
            ->where('cardStats.source', 'external')
            ->where('cardStats.refreshedAt', '2026-05-22T00:00:00Z')
            ->where('cardStats.externalError', false)
        );
});

it('falls back to local when deck has no archetype', function () {
    $deck = Deck::factory()->create(['archetype_id' => null, 'format' => 'Standard']);

    $this->get(route('decks.card-stats', $deck).'?card_stats_source=external')
        ->assertInertia(fn (Assert $page) => $page
            ->where('cardStats.source', 'local')
            ->where('cardStats.externalError', true)
        );
});

it('falls back to local with error flag when api fails', function () {
    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['archetype_id' => $archetype->id, 'format' => 'Standard']);

    Http::fake(['*' => Http::response(['message' => 'oops'], 500)]);

    $this->get(route('decks.card-stats', $deck).'?card_stats_source=external')
        ->assertInertia(fn (Assert $page) => $page
            ->where('cardStats.source', 'local')
            ->where('cardStats.externalError', true)
        );
});
