<?php

use App\Models\Archetype;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates meta only when no cards are posted', function () {
    $archetype = Archetype::factory()->create([
        'name' => 'Old',
        'format' => 'modern',
    ]);
    $deck = $archetype->decks()->create([
        'uuid' => 'existing-uuid',
        'seen_count' => 1,
        'last_synced_at' => now(),
    ]);

    $this->put(route('archetypes.update', $archetype), [
        'name' => 'New',
        'format' => 'legacy',
        'color_identity' => 'U,B',
    ])->assertRedirect(route('archetypes.show', $archetype));

    expect($archetype->fresh()->name)->toBe('New');
    expect($archetype->fresh()->decks)->toHaveCount(1);
    expect($archetype->fresh()->decks->first()->id)->toBe($deck->id);
});

it('updates meta and adds a new variant when cards are posted', function () {
    $archetype = Archetype::factory()->create();
    Card::factory()->create(['oracle_id' => 'oracle-1']);

    $this->put(route('archetypes.update', $archetype), [
        'name' => 'Brew',
        'format' => 'modern',
        'color_identity' => null,
        'cards' => [
            ['oracle_id' => 'oracle-1', 'mtgo_id' => 1, 'quantity' => 4, 'sideboard' => false],
        ],
    ])->assertRedirect(route('archetypes.show', $archetype));

    expect($archetype->fresh()->decks)->toHaveCount(1);
});

it('returns a validation error for a duplicate variant', function () {
    $archetype = Archetype::factory()->create();
    Card::factory()->create(['oracle_id' => 'oracle-1']);

    $this->put(route('archetypes.update', $archetype), [
        'name' => 'Brew',
        'format' => 'modern',
        'color_identity' => null,
        'cards' => [
            ['oracle_id' => 'oracle-1', 'mtgo_id' => 1, 'quantity' => 4, 'sideboard' => false],
        ],
    ]);

    $response = $this->put(route('archetypes.update', $archetype), [
        'name' => 'Brew',
        'format' => 'modern',
        'color_identity' => null,
        'cards' => [
            ['oracle_id' => 'oracle-1', 'mtgo_id' => 1, 'quantity' => 4, 'sideboard' => false],
        ],
    ]);

    $response->assertSessionHasErrors(['cards']);
    expect($archetype->fresh()->decks)->toHaveCount(1);
});

it('403s when editing a fallback archetype', function () {
    $archetype = Archetype::factory()->fallback()->create();

    $this->put(route('archetypes.update', $archetype), [
        'name' => 'X',
        'format' => 'modern',
        'color_identity' => null,
    ])->assertForbidden();
});

it('allows two variants that differ only in quantity', function () {
    $archetype = Archetype::factory()->create();
    Card::factory()->create(['oracle_id' => 'oracle-1']);

    $this->put(route('archetypes.update', $archetype), [
        'name' => 'Brew',
        'format' => 'modern',
        'color_identity' => null,
        'cards' => [
            ['oracle_id' => 'oracle-1', 'mtgo_id' => 1, 'quantity' => 4, 'sideboard' => false],
        ],
    ])->assertRedirect(route('archetypes.show', $archetype));

    $this->put(route('archetypes.update', $archetype), [
        'name' => 'Brew',
        'format' => 'modern',
        'color_identity' => null,
        'cards' => [
            ['oracle_id' => 'oracle-1', 'mtgo_id' => 1, 'quantity' => 3, 'sideboard' => false],
        ],
    ])->assertRedirect(route('archetypes.show', $archetype));

    expect($archetype->fresh()->decks)->toHaveCount(2);
});
