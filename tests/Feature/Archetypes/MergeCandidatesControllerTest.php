<?php

use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns same-format non-fallback non-merged candidates excluding self', function (): void {
    $source = Archetype::factory()->create(['format' => 'modern', 'is_fallback' => false, 'name' => 'Source']);

    Archetype::factory()->create(['format' => 'modern', 'is_fallback' => false, 'name' => 'Valid']);
    Archetype::factory()->create(['format' => 'pioneer', 'is_fallback' => false, 'name' => 'WrongFormat']);
    Archetype::factory()->create(['format' => 'modern', 'is_fallback' => true, 'name' => 'Fallback']);

    $parent = Archetype::factory()->create(['format' => 'modern', 'name' => 'Parent']);
    Archetype::factory()->create([
        'format' => 'modern',
        'merged_into_id' => $parent->id,
        'name' => 'Merged',
    ]);

    $response = $this->getJson(route('archetypes.merge-candidates', $source));

    $response->assertOk();
    $names = collect($response->json())->pluck('name')->all();

    expect($names)->toContain('Valid')
        ->toContain('Parent')
        ->not->toContain('WrongFormat')
        ->not->toContain('Fallback')
        ->not->toContain('Merged')
        ->not->toContain('Source');
});

it('returns candidates ordered by name', function (): void {
    $source = Archetype::factory()->create(['format' => 'modern']);
    Archetype::factory()->create(['format' => 'modern', 'name' => 'Charlie']);
    Archetype::factory()->create(['format' => 'modern', 'name' => 'Alpha']);
    Archetype::factory()->create(['format' => 'modern', 'name' => 'Bravo']);

    $response = $this->getJson(route('archetypes.merge-candidates', $source));

    $names = collect($response->json())->pluck('name')->all();

    expect($names)->toBe(['Alpha', 'Bravo', 'Charlie']);
});
