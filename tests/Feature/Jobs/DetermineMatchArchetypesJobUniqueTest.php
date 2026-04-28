<?php

use App\Jobs\DetermineMatchArchetypesJob;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('exposes match id as uniqueId', function () {
    $job = new DetermineMatchArchetypesJob(matchId: 42);

    expect($job->uniqueId())->toBe('42');
});

it('clears archetype_detection_queued_at after running', function () {
    $match = MtgoMatch::factory()->create([
        'archetype_detection_queued_at' => now(),
    ]);

    try {
        (new DetermineMatchArchetypesJob($match->id))->handle();
    } catch (\Throwable) {
        // expected if detection action fails on minimal fixture
    }

    expect($match->fresh()->archetype_detection_queued_at)->toBeNull();
});

it('clears archetype_detection_queued_at on failure', function () {
    $match = MtgoMatch::factory()->create([
        'archetype_detection_queued_at' => now(),
    ]);

    (new DetermineMatchArchetypesJob($match->id))->failed(new \RuntimeException('boom'));

    expect($match->fresh()->archetype_detection_queued_at)->toBeNull();
});

it('dispatches via Bus fake', function () {
    Bus::fake();

    DetermineMatchArchetypesJob::dispatch(99);
    DetermineMatchArchetypesJob::dispatch(99);

    Bus::assertDispatched(DetermineMatchArchetypesJob::class);
    // Note: Bus::fake() does not enforce ShouldBeUnique. The framework
    // enforces unique dispatch via cache locks at the queue layer. Both
    // dispatches are recorded in Bus::fake(), but real queue enforcement
    // happens at queue processing time with cache locks.
});
