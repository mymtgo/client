<?php

use App\Models\LogCursor;
use App\Models\LogInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('belongs to a log instance', function () {
    $instance = LogInstance::factory()->create();
    $cursor = LogCursor::create(['log_instance_id' => $instance->id]);

    expect($cursor->logInstance->is($instance))->toBeTrue();
});

it('defaults byte_offset and stuck_ticks to zero', function () {
    $instance = LogInstance::factory()->create();
    $cursor = LogCursor::create(['log_instance_id' => $instance->id]);

    expect($cursor->byte_offset)->toBe(0)
        ->and($cursor->stuck_ticks)->toBe(0)
        ->and($cursor->last_observed_size)->toBe(0);
});
