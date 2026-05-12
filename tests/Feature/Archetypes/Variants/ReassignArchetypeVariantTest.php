<?php

use App\Actions\Archetypes\ReassignArchetypeVariant;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('moves variant to target archetype', function (): void {
    $source = Archetype::factory()->create(['format' => 'modern']);
    $target = Archetype::factory()->create(['format' => 'modern']);
    ArchetypeDeck::factory()->for($source)->create();
    $variant = ArchetypeDeck::factory()->for($source)->create();

    ReassignArchetypeVariant::run($variant, $target);

    expect($variant->fresh()->archetype_id)->toBe($target->id);
});

it('flags source as merged into target when source has no variants left', function (): void {
    $source = Archetype::factory()->create(['format' => 'modern']);
    $target = Archetype::factory()->create(['format' => 'modern']);
    $variant = ArchetypeDeck::factory()->for($source)->create();

    ReassignArchetypeVariant::run($variant, $target);

    expect($source->fresh()->merged_into_id)->toBe($target->id);
});

it('does not merge source when source still has variants', function (): void {
    $source = Archetype::factory()->create(['format' => 'modern']);
    $target = Archetype::factory()->create(['format' => 'modern']);
    ArchetypeDeck::factory()->for($source)->create();
    $variant = ArchetypeDeck::factory()->for($source)->create();

    ReassignArchetypeVariant::run($variant, $target);

    expect($source->fresh()->merged_into_id)->toBeNull();
});

it('refuses cross-format reassign', function (): void {
    $source = Archetype::factory()->create(['format' => 'modern']);
    $target = Archetype::factory()->create(['format' => 'pioneer']);
    $variant = ArchetypeDeck::factory()->for($source)->create();

    expect(fn () => ReassignArchetypeVariant::run($variant, $target))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses reassign to fallback', function (): void {
    $source = Archetype::factory()->create(['format' => 'modern', 'is_fallback' => false]);
    $target = Archetype::factory()->create(['format' => 'modern', 'is_fallback' => true]);
    $variant = ArchetypeDeck::factory()->for($source)->create();

    expect(fn () => ReassignArchetypeVariant::run($variant, $target))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses reassign to a merged target', function (): void {
    $upstream = Archetype::factory()->create(['format' => 'modern']);
    $source = Archetype::factory()->create(['format' => 'modern']);
    $target = Archetype::factory()->create([
        'format' => 'modern',
        'merged_into_id' => $upstream->id,
    ]);
    $variant = ArchetypeDeck::factory()->for($source)->create();

    expect(fn () => ReassignArchetypeVariant::run($variant, $target))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses reassign when source is already merged', function (): void {
    $upstream = Archetype::factory()->create(['format' => 'modern']);
    $source = Archetype::factory()->create([
        'format' => 'modern',
        'merged_into_id' => $upstream->id,
    ]);
    $target = Archetype::factory()->create(['format' => 'modern']);
    $variant = ArchetypeDeck::factory()->for($source)->create();

    expect(fn () => ReassignArchetypeVariant::run($variant, $target))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses self reassign', function (): void {
    $archetype = Archetype::factory()->create(['format' => 'modern']);
    $variant = ArchetypeDeck::factory()->for($archetype)->create();

    expect(fn () => ReassignArchetypeVariant::run($variant, $archetype))
        ->toThrow(InvalidArgumentException::class);
});
