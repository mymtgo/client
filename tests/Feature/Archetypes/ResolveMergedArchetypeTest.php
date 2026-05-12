<?php

use App\Actions\Archetypes\ResolveMergedArchetype;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('passes through when archetype has no merged_into_id', function (): void {
    $archetype = Archetype::factory()->create();

    $result = ResolveMergedArchetype::run($archetype->id, 42);

    expect($result)->toBe([
        'archetype_id' => $archetype->id,
        'archetype_deck_id' => 42,
    ]);
});

it('passes through when archetype does not exist', function (): void {
    $result = ResolveMergedArchetype::run(999_999, 7);

    expect($result)->toBe([
        'archetype_id' => 999_999,
        'archetype_deck_id' => 7,
    ]);
});

it('redirects to parent and picks the latest variant by last_synced_at', function (): void {
    $parent = Archetype::factory()->create();
    $older = ArchetypeDeck::factory()->for($parent)->create([
        'last_synced_at' => now()->subDay(),
    ]);
    $newer = ArchetypeDeck::factory()->for($parent)->create([
        'last_synced_at' => now(),
    ]);
    $source = Archetype::factory()->create(['merged_into_id' => $parent->id]);

    $result = ResolveMergedArchetype::run($source->id, null);

    expect($result)->toBe([
        'archetype_id' => $parent->id,
        'archetype_deck_id' => $newer->id,
    ]);
});

it('falls back to created_at when last_synced_at is null', function (): void {
    $parent = Archetype::factory()->create();
    $older = ArchetypeDeck::factory()->for($parent)->create([
        'last_synced_at' => null,
        'created_at' => now()->subWeek(),
    ]);
    $newer = ArchetypeDeck::factory()->for($parent)->create([
        'last_synced_at' => null,
        'created_at' => now(),
    ]);
    $source = Archetype::factory()->create(['merged_into_id' => $parent->id]);

    $result = ResolveMergedArchetype::run($source->id, null);

    expect($result['archetype_id'])->toBe($parent->id)
        ->and($result['archetype_deck_id'])->toBe($newer->id);
});

it('returns null variant when parent has no decks', function (): void {
    $parent = Archetype::factory()->create();
    $source = Archetype::factory()->create(['merged_into_id' => $parent->id]);

    $result = ResolveMergedArchetype::run($source->id, 17);

    expect($result)->toBe([
        'archetype_id' => $parent->id,
        'archetype_deck_id' => null,
    ]);
});

it('does not chase chains beyond one hop', function (): void {
    $top = Archetype::factory()->create();
    $middle = Archetype::factory()->create(['merged_into_id' => $top->id]);
    $bottom = Archetype::factory()->create(['merged_into_id' => $middle->id]);

    $result = ResolveMergedArchetype::run($bottom->id, null);

    expect($result['archetype_id'])->toBe($middle->id);
});
