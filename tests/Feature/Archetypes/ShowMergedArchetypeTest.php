<?php

use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('returns mergedInto data on detail page when merged_into_id is set', function (): void {
    $parent = Archetype::factory()->create(['name' => 'Parent Archetype']);
    $source = Archetype::factory()->create(['merged_into_id' => $parent->id]);

    $this->get(route('archetypes.show', $source))
        ->assertInertia(fn (Assert $page) => $page
            ->component('archetypes/Show')
            ->where('detail.mergedInto.id', $parent->id)
            ->where('detail.mergedInto.name', 'Parent Archetype'),
        );
});

it('returns null mergedInto on detail page when archetype is standalone', function (): void {
    $source = Archetype::factory()->create(['merged_into_id' => null]);

    $this->get(route('archetypes.show', $source))
        ->assertInertia(fn (Assert $page) => $page
            ->where('detail.mergedInto', null),
        );
});
