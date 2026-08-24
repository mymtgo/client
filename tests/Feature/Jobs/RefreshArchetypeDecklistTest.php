<?php

use App\Facades\AppSettings;
use App\Jobs\RefreshArchetypeDecklist;
use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('logs and swallows when failed() runs', function () {
    Log::spy();

    $job = new RefreshArchetypeDecklist(42);
    $job->failed(new RuntimeException('boom'));

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) {
        return str_contains($message, 'RefreshArchetypeDecklist')
            && $context['archetype_id'] === 42
            && $context['exception'] === 'boom';
    });
});

it('does not call the API while offline mode is on', function () {
    AppSettings::setOffline(true);

    // Clear the global Http::fake() from Pest.php so a real request would
    // be caught by preventStrayRequests() instead of silently succeeding.
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
    Http::preventStrayRequests();

    $archetype = Archetype::factory()->create(['uuid' => 'arch-uuid']);

    (new RefreshArchetypeDecklist($archetype->id))->handle();

    expect($archetype->fresh()->decklist_downloaded_at)->toBeNull();
});

it('logs a warning and does not throw when the download fails mid-batch', function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    $archetype = Archetype::factory()->create(['uuid' => 'arch-uuid']);

    Http::fake([
        '*/api/archetypes/arch-uuid/decklists' => Http::response(['error' => 'nope'], 500),
    ]);

    Log::spy();

    // A batch job throwing tries=3 with [10, 60, 300]s backoff for every
    // archetype in the batch is exactly what the sibling job avoids —
    // this must degrade the same way, not rethrow.
    (new RefreshArchetypeDecklist($archetype->id))->handle();

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($archetype) {
        return str_contains($message, 'RefreshArchetypeDecklist')
            && $context['archetype_id'] === $archetype->id;
    });
});
