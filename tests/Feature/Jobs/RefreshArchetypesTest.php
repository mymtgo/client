<?php

use App\Facades\AppSettings;
use App\Jobs\RefreshArchetypeDecklist;
use App\Jobs\RefreshArchetypes;
use App\Models\Archetype;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    Http::fake([
        '*/api/archetypes' => Http::response([], 200),
    ]);
});

it('reports stale when no stamp exists', function () {
    AppSettings::forget('archetypes_last_refreshed_at');

    expect(RefreshArchetypes::isStale())->toBeTrue();
});

it('reports stale when last refresh is > 24h ago', function () {
    AppSettings::setArchetypesLastRefreshedAt(now()->subHours(25)->toIso8601String());

    expect(RefreshArchetypes::isStale())->toBeTrue();
});

it('reports fresh when last refresh is < 24h ago', function () {
    AppSettings::setArchetypesLastRefreshedAt(now()->subHours(2)->toIso8601String());

    expect(RefreshArchetypes::isStale())->toBeFalse();
});

it('no-ops when not stale', function () {
    AppSettings::setArchetypesLastRefreshedAt(now()->subHours(2)->toIso8601String());
    Bus::fake();

    (new RefreshArchetypes)->handle();

    Bus::assertNothingBatched();
});

it('dispatches a batch of RefreshArchetypeDecklist jobs when stale', function () {
    AppSettings::forget('archetypes_last_refreshed_at');
    Archetype::factory()->count(3)->create([
        'is_fallback' => false,
        'manual' => false,
        'merged_into_id' => null,
        'format' => 'modern',
    ]);

    Bus::fake();
    (new RefreshArchetypes)->handle();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 3
        && $batch->jobs->every(fn ($j) => $j instanceof RefreshArchetypeDecklist)
    );
});

it('skips manual, fallback, and merged archetypes', function () {
    AppSettings::forget('archetypes_last_refreshed_at');
    Archetype::factory()->create(['is_fallback' => false, 'manual' => false, 'merged_into_id' => null, 'format' => 'modern']);
    Archetype::factory()->create(['is_fallback' => true]);
    Archetype::factory()->create(['manual' => true]);
    $target = Archetype::factory()->create(['manual' => true]);
    Archetype::factory()->create(['merged_into_id' => $target->id]);

    Bus::fake();
    (new RefreshArchetypes)->handle();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
});

it('sets refresh_in_progress to true before dispatching', function () {
    AppSettings::forget('archetypes_last_refreshed_at');
    AppSettings::setArchetypesRefreshInProgress(false);
    Archetype::factory()->create(['is_fallback' => false, 'manual' => false, 'merged_into_id' => null, 'format' => 'modern']);

    Bus::fake();
    (new RefreshArchetypes)->handle();

    expect(AppSettings::archetypesRefreshInProgress())->toBeTrue();
});
