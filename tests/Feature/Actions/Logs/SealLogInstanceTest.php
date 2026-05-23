<?php

use App\Actions\Logs\SealLogInstance;
use App\Events\LogInstanceSealed;
use App\Models\LogInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(LazilyRefreshDatabase::class);

it('seals an instance with reason and timestamp', function () {
    Event::fake([LogInstanceSealed::class]);

    $instance = LogInstance::factory()->create();

    SealLogInstance::run($instance, 'truncated');

    $instance->refresh();

    expect($instance->sealed_at)->not->toBeNull()
        ->and($instance->seal_reason)->toBe('truncated');

    Event::assertDispatched(LogInstanceSealed::class, fn ($e) => $e->instanceId === $instance->id);
});

it('is idempotent: sealing already-sealed instance does not dispatch again', function () {
    Event::fake([LogInstanceSealed::class]);

    $instance = LogInstance::factory()->sealed('original')->create();

    SealLogInstance::run($instance, 'should_be_ignored');

    expect($instance->fresh()->seal_reason)->toBe('original');

    Event::assertNotDispatched(LogInstanceSealed::class);
});
