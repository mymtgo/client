<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

/**
 * Regression: a previous run of these migrations applied schema changes
 * but was interrupted before the migrations table recorded success.
 * The next launch retried the migrations, hit "duplicate column" or
 * "no such index", aborted the batch, and stranded users on a half-
 * migrated DB where the pipeline threw QueryException every 2s.
 *
 * These tests load each up() against an already-migrated DB and assert
 * it completes without throwing — proving re-runs against post-state
 * are safe.
 */
function loadMigration(string $filename): object
{
    return require database_path("migrations/{$filename}");
}

it('migration 100100 (add log_instance_id) is safe to re-run when column exists', function () {
    expect(Schema::hasColumn('log_events', 'log_instance_id'))->toBeTrue();

    $migration = loadMigration('2026_05_21_100100_add_log_instance_id_to_log_events_table.php');

    $migration->up();

    expect(Schema::hasColumn('log_events', 'log_instance_id'))->toBeTrue();
});

it('migration 100200 (rewrite log_cursors) is safe to re-run when new schema exists', function () {
    expect(Schema::hasTable('log_cursors'))->toBeTrue()
        ->and(Schema::hasColumn('log_cursors', 'log_instance_id'))->toBeTrue();

    $migration = loadMigration('2026_05_21_100200_rewrite_log_cursors_table.php');

    $migration->up();

    expect(Schema::hasColumn('log_cursors', 'log_instance_id'))->toBeTrue()
        ->and(Schema::hasColumn('log_cursors', 'byte_offset'))->toBeTrue()
        ->and(Schema::hasTable('log_cursors_legacy_snapshot'))->toBeFalse();
});

it('migration 100300 (backfill) is safe to re-run when no legacy snapshot exists', function () {
    expect(Schema::hasTable('log_cursors_legacy_snapshot'))->toBeFalse();

    $migration = loadMigration('2026_05_21_100300_backfill_log_instances.php');

    $migration->up();

    expect(true)->toBeTrue();
});

it('migration 100300 does not duplicate instances when re-run mid-flight', function () {
    Schema::create('log_cursors_legacy_snapshot', function ($table) {
        $table->id();
        $table->string('file_path', 1024);
        $table->unsignedBigInteger('byte_offset')->default(0);
        $table->unsignedBigInteger('file_mtime')->nullable();
        $table->unsignedBigInteger('file_size')->nullable();
        $table->string('head_hash', 40)->nullable();
        $table->string('local_username')->nullable();
        $table->timestamps();
    });

    DB::table('log_cursors_legacy_snapshot')->insert([
        'id' => 1,
        'file_path' => 'C:\\Users\\Test\\Logs\\mtgo.log',
        'byte_offset' => 1024,
        'head_hash' => str_repeat('a', 40),
        'local_username' => 'TestUser',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = loadMigration('2026_05_21_100300_backfill_log_instances.php');

    $migration->up();

    $countAfterFirstRun = DB::table('log_instances')->where('seal_reason', 'pre_migration')->count();

    Schema::create('log_cursors_legacy_snapshot', function ($table) {
        $table->id();
        $table->string('file_path', 1024);
        $table->unsignedBigInteger('byte_offset')->default(0);
        $table->unsignedBigInteger('file_mtime')->nullable();
        $table->unsignedBigInteger('file_size')->nullable();
        $table->string('head_hash', 40)->nullable();
        $table->string('local_username')->nullable();
        $table->timestamps();
    });

    DB::table('log_cursors_legacy_snapshot')->insert([
        'id' => 1,
        'file_path' => 'C:\\Users\\Test\\Logs\\mtgo.log',
        'byte_offset' => 1024,
        'head_hash' => str_repeat('a', 40),
        'local_username' => 'TestUser',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('log_instances')->where('seal_reason', 'pre_migration')->count())
        ->toBe($countAfterFirstRun);
});

it('migration 100400 (swap unique constraint) is safe to re-run when new index exists', function () {
    $indexes = collect(DB::select("PRAGMA index_list('log_events')"))->pluck('name')->all();
    expect($indexes)->toContain('log_events_instance_start_unique')
        ->and($indexes)->not->toContain('log_events_file_start_unique');

    $migration = loadMigration('2026_05_21_100400_swap_log_events_unique_constraint.php');

    $migration->up();

    $indexes = collect(DB::select("PRAGMA index_list('log_events')"))->pluck('name')->all();
    expect($indexes)->toContain('log_events_instance_start_unique');
});
