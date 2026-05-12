<?php

use App\Actions\Archetypes\UpdateArchetypeMeta;
use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates name, format and color identity', function () {
    $archetype = Archetype::factory()->create([
        'name' => 'Old',
        'format' => 'modern',
        'color_identity' => 'R',
    ]);

    UpdateArchetypeMeta::run($archetype, 'New', 'Legacy', 'U,B');

    $archetype->refresh();
    expect($archetype->name)->toBe('New');
    expect($archetype->format)->toBe('legacy');
    expect($archetype->color_identity)->toBe('U,B');
});

it('accepts null color identity', function () {
    $archetype = Archetype::factory()->create(['color_identity' => 'R']);

    UpdateArchetypeMeta::run($archetype, 'X', 'modern', null);

    expect($archetype->fresh()->color_identity)->toBeNull();
});

it('preserves the manual flag on update', function () {
    $systemArchetype = Archetype::factory()->create(['manual' => false]);
    UpdateArchetypeMeta::run($systemArchetype, 'Renamed', 'modern', 'W');
    expect($systemArchetype->fresh()->manual)->toBeFalse();

    $manualArchetype = Archetype::factory()->create(['manual' => true]);
    UpdateArchetypeMeta::run($manualArchetype, 'Renamed', 'modern', 'W');
    expect($manualArchetype->fresh()->manual)->toBeTrue();
});

it('preserves decklist_downloaded_at on update', function () {
    $downloadedAt = now()->subDays(3);
    $archetype = Archetype::factory()->create([
        'decklist_downloaded_at' => $downloadedAt,
    ]);

    UpdateArchetypeMeta::run($archetype, 'X', 'modern', null);

    expect($archetype->fresh()->decklist_downloaded_at->timestamp)
        ->toBe($downloadedAt->timestamp);
});
