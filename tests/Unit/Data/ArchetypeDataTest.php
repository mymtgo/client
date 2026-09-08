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

it('exposes colour identity in canonical comma separated form', function (): void {
    $concatenated = Archetype::factory()->create(['color_identity' => 'UB']);
    $commaSeparated = Archetype::factory()->create(['color_identity' => 'U,B']);

    expect(ArchetypeData::fromModel($concatenated)->colorIdentity)->toBe('U,B')
        ->and(ArchetypeData::fromModel($commaSeparated)->colorIdentity)->toBe('U,B');
});

it('exposes a blank colour identity as null', function (): void {
    $archetype = Archetype::factory()->create(['color_identity' => '']);

    expect(ArchetypeData::fromModel($archetype)->colorIdentity)->toBeNull();
});
