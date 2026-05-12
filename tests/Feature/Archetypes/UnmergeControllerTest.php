<?php

use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('unmerges archetype and redirects back', function (): void {
    $parent = Archetype::factory()->create();
    $source = Archetype::factory()->create(['merged_into_id' => $parent->id]);

    $response = $this->post(route('archetypes.unmerge', $source));

    $response->assertRedirect(route('archetypes.show', $source));
    expect($source->fresh()->merged_into_id)->toBeNull();
});

it('errors when archetype is not merged', function (): void {
    $source = Archetype::factory()->create(['merged_into_id' => null]);

    $response = $this->from(route('archetypes.show', $source))
        ->post(route('archetypes.unmerge', $source));

    $response->assertStatus(500);
    expect($source->fresh()->merged_into_id)->toBeNull();
});
