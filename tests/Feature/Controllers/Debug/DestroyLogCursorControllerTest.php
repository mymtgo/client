<?php

use App\Facades\AppSettings;
use App\Models\LogCursor;
use App\Models\LogInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    AppSettings::set('debug_mode', true);
});

it('deletes a log cursor so the pipeline recreates it from byte_offset 0', function () {
    $instance = LogInstance::factory()->create();
    $cursor = LogCursor::create([
        'log_instance_id' => $instance->id,
        'byte_offset' => 12345,
        'last_observed_size' => 50000,
        'last_advance_at' => now(),
        'stuck_ticks' => 7,
    ]);

    $this->delete("/debug/log-cursors/{$cursor->id}")->assertRedirect();

    expect(LogCursor::find($cursor->id))->toBeNull();
});

it('returns 404 when the cursor does not exist', function () {
    $this->delete('/debug/log-cursors/999999')->assertNotFound();
});
