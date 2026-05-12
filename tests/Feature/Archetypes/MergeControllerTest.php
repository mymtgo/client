<?php

use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('merges archetype into parent and redirects back', function (): void {
    $parent = Archetype::factory()->create(['format' => 'modern']);
    $source = Archetype::factory()->create(['format' => 'modern']);

    $response = $this->post(route('archetypes.merge', $source), [
        'parent_id' => $parent->id,
    ]);

    $response->assertRedirect(route('archetypes.show', $source));
    expect($source->fresh()->merged_into_id)->toBe($parent->id);
});

it('rejects merge across formats with a validation error', function (): void {
    $parent = Archetype::factory()->create(['format' => 'modern']);
    $source = Archetype::factory()->create(['format' => 'pioneer']);

    $response = $this->from(route('archetypes.show', $source))
        ->post(route('archetypes.merge', $source), ['parent_id' => $parent->id]);

    $response->assertSessionHasErrors('parent_id');
    expect($source->fresh()->merged_into_id)->toBeNull();
});

it('rejects merge when parent does not exist', function (): void {
    $source = Archetype::factory()->create(['format' => 'modern']);

    $response = $this->from(route('archetypes.show', $source))
        ->post(route('archetypes.merge', $source), ['parent_id' => 999_999]);

    $response->assertSessionHasErrors('parent_id');
});

it('rejects self merge', function (): void {
    $source = Archetype::factory()->create(['format' => 'modern']);

    $response = $this->from(route('archetypes.show', $source))
        ->post(route('archetypes.merge', $source), ['parent_id' => $source->id]);

    $response->assertSessionHasErrors('parent_id');
    expect($source->fresh()->merged_into_id)->toBeNull();
});
