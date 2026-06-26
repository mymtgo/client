<?php

use App\Models\Archetype;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults is_opponent to false and casts it as bool', function () {
    $match = MtgoMatch::factory()->create();
    $archetype = Archetype::factory()->create();

    $row = MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype->id,
    ]);

    expect($row->fresh()->is_opponent)->toBeFalse();
});

it('stores is_opponent true', function () {
    $match = MtgoMatch::factory()->create();
    $archetype = Archetype::factory()->create();

    $row = MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype->id,
        'is_opponent' => true,
    ]);

    expect($row->fresh()->is_opponent)->toBeTrue();
});
