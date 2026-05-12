<?php

use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('exposes mergedInto relation returning the parent archetype', function (): void {
    $parent = Archetype::factory()->create();
    $source = Archetype::factory()->create(['merged_into_id' => $parent->id]);

    expect($source->mergedInto)->not->toBeNull()
        ->and($source->mergedInto->id)->toBe($parent->id);
});

it('exposes mergedFrom relation returning child archetypes', function (): void {
    $parent = Archetype::factory()->create();
    Archetype::factory()->create(['merged_into_id' => $parent->id]);
    Archetype::factory()->create(['merged_into_id' => $parent->id]);

    expect($parent->mergedFrom)->toHaveCount(2);
});
