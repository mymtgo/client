<?php

use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Models\LogCursor;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/mtgo_ingest_logs_'.uniqid();
    mkdir($this->tempDir.'/live', 0755, true);
    mkdir($this->tempDir.'/beta', 0755, true);
    mkdir($this->tempDir.'/data', 0755, true);

    // Satisfy MtgoManager::canRun() — needs watcher_active, a valid log path
    // (contains mtgo.log), and a valid data path (contains Match_GameLog_*).
    AppSettings::setWatcherActive(true);
    AppSettings::setLogPath($this->tempDir);
    AppSettings::setLogDataPath($this->tempDir.'/data');
    touch($this->tempDir.'/data/Match_GameLog_fake.dat');

    // FindMtgoLogPath caches results for 60s — flush between tests.
    Cache::forget('mtgo.all_log_paths');
});

afterEach(function () {
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tempDir);
    }
});

it('ingests every mtgo.log file found under the log path', function () {
    $fixture = file_get_contents(base_path('tests/Fixtures/sample_log.txt'));

    $older = $this->tempDir.'/live/mtgo.log';
    $newer = $this->tempDir.'/beta/mtgo.log';

    file_put_contents($older, $fixture);
    file_put_contents($newer, $fixture);

    // Ensure distinct mtimes so ordering is deterministic (older -> newer)
    touch($older, time() - 60);
    touch($newer, time());

    // Normalize paths to real paths (handles macOS symlinks like /var -> /private/var)
    $older = realpath($older);
    $newer = realpath($newer);

    Mtgo::ingestLogs();

    expect(LogCursor::where('file_path', $older)->exists())->toBeTrue();
    expect(LogCursor::where('file_path', $newer)->exists())->toBeTrue();
    expect(LogEvent::where('file_path', $older)->exists())->toBeTrue();
    expect(LogEvent::where('file_path', $newer)->exists())->toBeTrue();
    expect(LogCursor::count())->toBe(2);
});
