<?php

use App\Models\LogInstance;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function runSidecarRemovalMigration(): void
{
    (require database_path('migrations/2026_10_03_000001_remove_sidecar_capture.php'))->up();
}

beforeEach(function () {
    // Recreate the pre-removal shape: RefreshDatabase already ran this migration.
    Schema::create('game_events', function (Blueprint $table) {
        $table->id();
        $table->foreignId('log_instance_id')->nullable()->constrained()->cascadeOnDelete();
    });
    Schema::create('game_field_diffs', function (Blueprint $table) {
        $table->id();
    });
    Schema::table('games', function (Blueprint $table) {
        $table->string('timeline_source')->nullable();
    });

    $this->helperInstance = LogInstance::factory()->create(['file_path' => 'C:\\mymtgo\\storage\\app\\sidecar\\events-aaaa.ndjson'])->id;
    $this->mtgoInstance = LogInstance::factory()->create(['file_path' => 'C:\\MTGO\\mtgo.log'])->id;

    DB::table('log_cursors')->insert([
        ['log_instance_id' => $this->helperInstance, 'byte_offset' => 10],
        ['log_instance_id' => $this->mtgoInstance, 'byte_offset' => 20],
    ]);
    DB::table('game_events')->insert(['log_instance_id' => $this->helperInstance]);
    DB::table('jobs')->insert([
        ['queue' => 'sidecar', 'payload' => '{}', 'attempts' => 0, 'available_at' => 0, 'created_at' => 0],
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => 0, 'created_at' => 0],
    ]);
});

it('drops the raw capture tables and the timeline source column', function () {
    runSidecarRemovalMigration();

    expect(Schema::hasTable('game_events'))->toBeFalse()
        ->and(Schema::hasTable('game_field_diffs'))->toBeFalse()
        ->and(Schema::hasColumn('games', 'timeline_source'))->toBeFalse();
});

it('removes helper log rows and keeps the mtgo log cursor', function () {
    runSidecarRemovalMigration();

    expect(DB::table('log_instances')->pluck('id')->all())->toBe([$this->mtgoInstance])
        ->and(DB::table('log_cursors')->pluck('log_instance_id')->all())->toBe([$this->mtgoInstance]);
});

it('removes queued helper download jobs only', function () {
    runSidecarRemovalMigration();

    expect(DB::table('jobs')->pluck('queue')->all())->toBe(['default']);
});

it('keeps the player clock columns and replay frames', function () {
    runSidecarRemovalMigration();

    expect(Schema::hasTable('game_timelines'))->toBeTrue()
        ->and(Schema::hasColumns('game_player', ['clock_remaining_ms_start', 'clock_remaining_ms_end', 'clock_remaining_ms_min', 'sideboard_ms_used']))->toBeTrue();
});

it('is safe to run twice', function () {
    runSidecarRemovalMigration();
    runSidecarRemovalMigration();

    expect(Schema::hasTable('game_events'))->toBeFalse()
        ->and(DB::table('log_instances')->count())->toBe(1);
});
