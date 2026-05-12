<?php

use App\Actions\Archetypes\MergeArchetype;
use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sets merged_into_id on source', function (): void {
    $parent = Archetype::factory()->create(['format' => 'modern']);
    $source = Archetype::factory()->create(['format' => 'modern']);

    MergeArchetype::run($source, $parent);

    expect($source->fresh()->merged_into_id)->toBe($parent->id);
});

it('refuses cross-format merge', function (): void {
    $parent = Archetype::factory()->create(['format' => 'modern']);
    $source = Archetype::factory()->create(['format' => 'pioneer']);

    expect(fn () => MergeArchetype::run($source, $parent))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses merge with fallback archetype as source', function (): void {
    $parent = Archetype::factory()->create(['format' => 'modern', 'is_fallback' => false]);
    $source = Archetype::factory()->create(['format' => 'modern', 'is_fallback' => true]);

    expect(fn () => MergeArchetype::run($source, $parent))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses merge with fallback archetype as parent', function (): void {
    $parent = Archetype::factory()->create(['format' => 'modern', 'is_fallback' => true]);
    $source = Archetype::factory()->create(['format' => 'modern', 'is_fallback' => false]);

    expect(fn () => MergeArchetype::run($source, $parent))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses merge if source already merged', function (): void {
    $original = Archetype::factory()->create(['format' => 'modern']);
    $parent = Archetype::factory()->create(['format' => 'modern']);
    $source = Archetype::factory()->create([
        'format' => 'modern',
        'merged_into_id' => $original->id,
    ]);

    expect(fn () => MergeArchetype::run($source, $parent))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses merge if parent already merged', function (): void {
    $upstream = Archetype::factory()->create(['format' => 'modern']);
    $parent = Archetype::factory()->create([
        'format' => 'modern',
        'merged_into_id' => $upstream->id,
    ]);
    $source = Archetype::factory()->create(['format' => 'modern']);

    expect(fn () => MergeArchetype::run($source, $parent))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses self-merge', function (): void {
    $archetype = Archetype::factory()->create(['format' => 'modern']);

    expect(fn () => MergeArchetype::run($archetype, $archetype))
        ->toThrow(InvalidArgumentException::class);
});
