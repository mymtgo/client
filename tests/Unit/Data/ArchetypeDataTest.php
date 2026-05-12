<?php

use App\Data\Front\ArchetypeData;
use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('includes merged_into_id when present', function (): void {
    $parent = Archetype::factory()->create();
    $source = Archetype::factory()->create(['merged_into_id' => $parent->id]);

    $data = ArchetypeData::fromModel($source);

    expect($data->mergedIntoId)->toBe($parent->id);
});

it('returns null mergedIntoId when archetype is standalone', function (): void {
    $archetype = Archetype::factory()->create(['merged_into_id' => null]);

    $data = ArchetypeData::fromModel($archetype);

    expect($data->mergedIntoId)->toBeNull();
});
