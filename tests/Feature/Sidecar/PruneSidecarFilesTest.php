<?php

use App\Actions\Sidecar\PruneSidecarFiles;
use App\Facades\AppSettings;
use App\Models\GameEvent;
use App\Models\LogInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('removes sealed sidecar files older than retention and keeps the rest', function () {
    $dir = sys_get_temp_dir().'/sidecar-prune-'.uniqid();
    mkdir($dir);
    AppSettings::setSidecarDirectory($dir);

    $old = $dir.'/events-old.ndjson';
    $fresh = $dir.'/events-fresh.ndjson';
    $live = $dir.'/events-live.ndjson';
    foreach ([$old, $fresh, $live] as $f) {
        file_put_contents($f, "{}\n");
    }

    $oldInstance = LogInstance::factory()->create(['file_path' => $old, 'sealed_at' => now()->subDays(40), 'seal_reason' => 'session_rotated']);
    GameEvent::factory()->create(['log_instance_id' => $oldInstance->id]);
    LogInstance::factory()->create(['file_path' => $fresh, 'sealed_at' => now()->subDays(2), 'seal_reason' => 'session_rotated']);
    LogInstance::factory()->create(['file_path' => $live, 'sealed_at' => null]);

    $deleted = PruneSidecarFiles::run(30);

    expect($deleted)->toBe(1)
        ->and(is_file($old))->toBeFalse()
        ->and(is_file($fresh))->toBeTrue()
        ->and(is_file($live))->toBeTrue()
        ->and(LogInstance::where('file_path', $old)->exists())->toBeFalse()
        ->and(GameEvent::count())->toBe(0);

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

it('returns zero and does not throw when the sidecar directory does not exist', function () {
    $dir = sys_get_temp_dir().'/sidecar-prune-missing-'.uniqid();
    AppSettings::setSidecarDirectory($dir);

    expect(PruneSidecarFiles::run(30))->toBe(0);
});

it('prunes the row even when the file is already gone from disk', function () {
    $dir = sys_get_temp_dir().'/sidecar-prune-missingfile-'.uniqid();
    mkdir($dir);
    AppSettings::setSidecarDirectory($dir);

    $missing = $dir.'/events-missing.ndjson';
    // Never created on disk: proves a sealed row pointing at an already
    // missing file is still self-healed away rather than skipped forever.
    $instance = LogInstance::factory()->create(['file_path' => $missing, 'sealed_at' => now()->subDays(40), 'seal_reason' => 'session_rotated']);

    $deleted = PruneSidecarFiles::run(30);

    expect($deleted)->toBe(1)
        ->and(LogInstance::whereKey($instance->id)->exists())->toBeFalse();

    rmdir($dir);
});

it('leaves the row in place when the file cannot be unlinked', function () {
    $dir = sys_get_temp_dir().'/sidecar-prune-locked-'.uniqid();
    mkdir($dir);
    AppSettings::setSidecarDirectory($dir);

    $locked = $dir.'/events-locked.ndjson';
    file_put_contents($locked, "{}\n");

    // Remove write permission on the directory so unlink() fails on the
    // existing file (still readable/listable, just not deletable).
    chmod($dir, 0555);

    $instance = LogInstance::factory()->create(['file_path' => $locked, 'sealed_at' => now()->subDays(40), 'seal_reason' => 'session_rotated']);

    $deleted = PruneSidecarFiles::run(30);

    chmod($dir, 0755);

    expect($deleted)->toBe(0)
        ->and(is_file($locked))->toBeTrue()
        ->and(LogInstance::whereKey($instance->id)->exists())->toBeTrue();

    unlink($locked);
    rmdir($dir);
})->skip(PHP_OS_FAMILY === 'Windows', 'chmod-based write denial is not meaningful on Windows');

it('does not let a literal % or _ in the sidecar directory act as a LIKE wildcard', function () {
    $suffix = uniqid();
    $dir = sys_get_temp_dir()."/sidecar_prune_{$suffix}";
    $adversarialDir = sys_get_temp_dir()."/sidecarXpruneX{$suffix}";
    mkdir($dir);
    mkdir($adversarialDir);
    AppSettings::setSidecarDirectory($dir);

    $adversarialFile = $adversarialDir.'/events-adversarial.ndjson';
    file_put_contents($adversarialFile, "{}\n");
    $adversarialInstance = LogInstance::factory()->create(['file_path' => $adversarialFile, 'sealed_at' => now()->subDays(40), 'seal_reason' => 'session_rotated']);

    $deleted = PruneSidecarFiles::run(30);

    expect($deleted)->toBe(0)
        ->and(is_file($adversarialFile))->toBeTrue()
        ->and(LogInstance::whereKey($adversarialInstance->id)->exists())->toBeTrue();

    unlink($adversarialFile);
    rmdir($dir);
    rmdir($adversarialDir);
});
