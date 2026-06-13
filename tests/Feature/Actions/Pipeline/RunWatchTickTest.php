<?php

use App\Actions\Pipeline\RunWatchTick;
use App\Facades\AppSettings;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->logDir = sys_get_temp_dir().'/mtgo_watch_'.bin2hex(random_bytes(4));
    @mkdir($this->logDir, 0777, true);
    // realpath() resolves /tmp -> /private/tmp on macOS so $this->logPath
    // matches the canonical path returned by Symfony Finder's getRealPath().
    $this->logDir = realpath($this->logDir) ?: $this->logDir;
    $this->logPath = $this->logDir.'/mtgo.log';

    AppSettings::setLogPath($this->logDir);
    AppSettings::setWatcherActive(true);
    Cache::flush();
    RunWatchTick::resetHeartbeatThrottle();
});

afterEach(function () {
    @unlink($this->logPath);
    @rmdir($this->logDir);
});

it('writes the daemon heartbeat every tick', function () {
    RunWatchTick::run([]);

    expect(Cache::get(RunWatchTick::HEARTBEAT_KEY))->not->toBeNull();
});

it('ingests when a log file grows', function () {
    file_put_contents($this->logPath, "12:00:00 [INF] (Login|MtGO Login Success) Username: SomeUser\n");

    $sizes = RunWatchTick::run([]);

    expect($sizes)->toHaveKey($this->logPath)
        ->and(LogEvent::count())->toBeGreaterThan(0);
});

it('skips the hot path when sizes are unchanged', function () {
    file_put_contents($this->logPath, "12:00:00 [INF] (Login|MtGO Login Success) Username: SomeUser\n");

    $sizes = RunWatchTick::run([]);
    $countAfterFirst = LogEvent::count();

    $second = RunWatchTick::run($sizes);

    expect($second)->toBe($sizes)
        ->and(LogEvent::count())->toBe($countAfterFirst);
});

it('does not ingest when the watcher toggle is off', function () {
    AppSettings::setWatcherActive(false);
    file_put_contents($this->logPath, "12:00:00 [INF] (Login|MtGO Login Success) Username: SomeUser\n");

    RunWatchTick::run([]);

    expect(LogEvent::count())->toBe(0)
        // Heartbeat still written while idling.
        ->and(Cache::get(RunWatchTick::HEARTBEAT_KEY))->not->toBeNull();
});

it('skips the tick and preserves lastSizes when the pipeline lock is held', function () {
    file_put_contents($this->logPath, "12:00:00 [INF] (Login|MtGO Login Success) Username: SomeUser\n");

    $lock = Cache::lock(RunWatchTick::LOCK_KEY, 30);
    expect($lock->get())->toBeTrue();

    try {
        $sizes = RunWatchTick::run(['stale' => 1]);

        expect(LogEvent::count())->toBe(0)
            // lastSizes returned untouched so the change re-detects next tick.
            ->and($sizes)->toBe(['stale' => 1]);
    } finally {
        $lock->release();
    }
});
