<?php

use App\Facades\AppSettings;
use App\Jobs\RefreshArchetypeDecklist;
use App\Jobs\RefreshArchetypes;
use App\Models\Archetype;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

it('dispatches a batch of RefreshArchetypeDecklist jobs', function () {
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
    Archetype::factory()->create(['is_fallback' => false, 'manual' => false, 'merged_into_id' => null, 'format' => 'modern']);
    Archetype::factory()->create(['is_fallback' => true]);
    Archetype::factory()->create(['manual' => true]);
    $target = Archetype::factory()->create(['manual' => true]);
    Archetype::factory()->create(['merged_into_id' => $target->id]);

    Bus::fake();
    (new RefreshArchetypes)->handle();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
});

it('no-ops when a refresh is already in progress', function () {
    AppSettings::setArchetypesRefreshInProgress(true);
    Archetype::factory()->create(['is_fallback' => false, 'manual' => false, 'merged_into_id' => null, 'format' => 'modern']);
    Bus::fake();

    (new RefreshArchetypes)->handle();

    Bus::assertNothingBatched();
});

it('sets refresh_in_progress to true before dispatching', function () {
    AppSettings::setArchetypesRefreshInProgress(false);
    Archetype::factory()->create(['is_fallback' => false, 'manual' => false, 'merged_into_id' => null, 'format' => 'modern']);

    Bus::fake();
    (new RefreshArchetypes)->handle();

    expect(AppSettings::archetypesRefreshInProgress())->toBeTrue();
});

it('queues both parent and child jobs on the archetypes queue', function () {
    expect((new RefreshArchetypes)->queue)->toBe('archetypes');
    expect((new RefreshArchetypeDecklist(1))->queue)->toBe('archetypes');
});

it('re-dispatching does not stack batches while one is in progress', function () {
    AppSettings::setArchetypesRefreshInProgress(false);
    Archetype::factory()->create(['is_fallback' => false, 'manual' => false, 'merged_into_id' => null, 'format' => 'modern']);

    Bus::fake();

    (new RefreshArchetypes)->handle();
    (new RefreshArchetypes)->handle();
    (new RefreshArchetypes)->handle();

    Bus::assertBatchCount(1);
});

it('does not download the archetype list itself', function () {
    Http::fake(fn () => throw new RuntimeException('unexpected HTTP call'));
    Archetype::factory()->create(['is_fallback' => false, 'manual' => false, 'merged_into_id' => null, 'format' => 'modern']);

    Bus::fake();
    (new RefreshArchetypes)->handle();

    Bus::assertBatchCount(1);
});
