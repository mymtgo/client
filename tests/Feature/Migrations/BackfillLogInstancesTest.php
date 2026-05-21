<?php

use App\Models\LogEvent;
use App\Models\LogInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('binds orphan log_events to a synthesized sealed instance', function () {
    // LazilyRefreshDatabase migrates the DB fresh — backfill already ran during
    // setUp but the DB is empty, so it had nothing to do. We simulate a row
    // that arrived AFTER the backfill (or was missed by it) by clearing the FK.
    $event = LogEvent::factory()->create([
        'file_path' => '/fake/legacy.log',
        'byte_offset_start' => 100,
        'byte_offset_end' => 200,
    ]);

    DB::table('log_events')->where('id', $event->id)->update(['log_instance_id' => null]);

    // Re-run only the backfill migration.
    $this->artisan('migrate:refresh', ['--path' => 'database/migrations/2026_05_21_100300_backfill_log_instances.php']);

    $event->refresh();

    expect($event->log_instance_id)->not->toBeNull();

    $instance = LogInstance::find($event->log_instance_id);

    expect($instance)->not->toBeNull()
        ->and($instance->isSealed())->toBeTrue()
        ->and($instance->seal_reason)->toStartWith('pre_migration');
});
