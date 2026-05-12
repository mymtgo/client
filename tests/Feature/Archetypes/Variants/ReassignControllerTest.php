<?php

use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reassigns variant and redirects to target show', function (): void {
    $source = Archetype::factory()->create(['format' => 'modern']);
    $target = Archetype::factory()->create(['format' => 'modern']);
    ArchetypeDeck::factory()->for($source)->create();
    $variant = ArchetypeDeck::factory()->for($source)->create();

    $response = $this->post(
        route('archetypes.variants.reassign', ['archetype' => $source->id, 'deck' => $variant->id]),
        ['target_id' => $target->id],
    );

    $response->assertRedirect(route('archetypes.show', $target));
    expect($variant->fresh()->archetype_id)->toBe($target->id);
});

it('rejects when variant belongs to a different archetype', function (): void {
    $other = Archetype::factory()->create(['format' => 'modern']);
    $source = Archetype::factory()->create(['format' => 'modern']);
    $target = Archetype::factory()->create(['format' => 'modern']);
    $variant = ArchetypeDeck::factory()->for($other)->create();

    $response = $this->from(route('archetypes.show', $source))->post(
        route('archetypes.variants.reassign', ['archetype' => $source->id, 'deck' => $variant->id]),
        ['target_id' => $target->id],
    );

    $response->assertSessionHasErrors('deck');
    expect($variant->fresh()->archetype_id)->toBe($other->id);
});

it('rejects cross-format reassign', function (): void {
    $source = Archetype::factory()->create(['format' => 'modern']);
    $target = Archetype::factory()->create(['format' => 'pioneer']);
    $variant = ArchetypeDeck::factory()->for($source)->create();

    $response = $this->from(route('archetypes.show', $source))->post(
        route('archetypes.variants.reassign', ['archetype' => $source->id, 'deck' => $variant->id]),
        ['target_id' => $target->id],
    );

    $response->assertSessionHasErrors('target_id');
    expect($variant->fresh()->archetype_id)->toBe($source->id);
});
