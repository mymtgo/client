<?php

use App\Models\LogEvent;
use App\Models\LogInstance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('enforces uniqueness on (log_instance_id, byte_offset_start)', function () {
    $instance = LogInstance::factory()->create();

    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'byte_offset_start' => 100,
        'byte_offset_end' => 200,
    ]);

    expect(fn () => LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'byte_offset_start' => 100,
        'byte_offset_end' => 250,
    ]))->toThrow(QueryException::class);
});

it('allows same byte_offset across different instances', function () {
    $a = LogInstance::factory()->create();
    $b = LogInstance::factory()->create();

    LogEvent::factory()->create(['log_instance_id' => $a->id, 'byte_offset_start' => 100]);
    LogEvent::factory()->create(['log_instance_id' => $b->id, 'byte_offset_start' => 100]);

    expect(LogEvent::count())->toBe(2);
});
