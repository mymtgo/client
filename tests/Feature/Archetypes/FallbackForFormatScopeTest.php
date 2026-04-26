<?php

use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forFormat scope returns format-specific archetypes plus fallbacks', function () {
    $modern = Archetype::factory()->create(['format' => 'modern']);
    $pauper = Archetype::factory()->create(['format' => 'pauper']);

    $results = Archetype::query()->forFormat('modern')->pluck('uuid')->all();

    expect($results)->toContain($modern->uuid)
        ->and($results)->toContain(Archetype::HOMEBREW_UUID)
        ->and($results)->toContain(Archetype::ROGUE_UUID)
        ->and($results)->not->toContain($pauper->uuid);
});

it('forFormat scope with null format only returns fallbacks', function () {
    Archetype::factory()->create(['format' => 'modern']);

    $results = Archetype::query()->forFormat(null)->pluck('uuid')->all();

    expect($results)->toContain(Archetype::HOMEBREW_UUID)
        ->and($results)->toContain(Archetype::ROGUE_UUID)
        ->and($results)->toHaveCount(2);
});
