<?php

use App\Actions\Logs\DetectStaleIngest;
use App\Actions\Logs\FindMtgoLogPath;
use App\Facades\AppSettings;
use App\Models\LogCursor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/mtgo_stale_'.uniqid();
    mkdir($this->tempDir.'/v1', 0755, true);

    AppSettings::setLogPath($this->tempDir);

    Cache::forget(FindMtgoLogPath::CACHE_KEY);
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

it('returns false and preserves cache when no recently modified log files exist', function () {
    $logPath = $this->tempDir.'/v1/mtgo.log';
    file_put_contents($logPath, 'hello');
    touch($logPath, time() - 600);

    Cache::put(FindMtgoLogPath::CACHE_KEY, collect(['cached']), 5);

    $result = DetectStaleIngest::run();

    expect($result)->toBeFalse();
    expect(Cache::get(FindMtgoLogPath::CACHE_KEY))->not->toBeNull();
});

it('returns false when a cursor advanced recently even if files are active', function () {
    $logPath = $this->tempDir.'/v1/mtgo.log';
    file_put_contents($logPath, 'hello');
    touch($logPath, time());

    LogCursor::create([
        'file_path' => $logPath,
        'byte_offset' => 5,
        'last_advanced_at' => now()->subSeconds(5),
    ]);

    Cache::put(FindMtgoLogPath::CACHE_KEY, collect(['cached']), 5);

    $result = DetectStaleIngest::run();

    expect($result)->toBeFalse();
    expect(Cache::get(FindMtgoLogPath::CACHE_KEY))->not->toBeNull();
});

it('forgets the path cache when a log file is active but no cursor has advanced', function () {
    $logPath = $this->tempDir.'/v1/mtgo.log';
    file_put_contents($logPath, 'hello');
    touch($logPath, time());

    LogCursor::create([
        'file_path' => $this->tempDir.'/other/mtgo.log',
        'byte_offset' => 5,
        'last_advanced_at' => now()->subMinutes(10),
    ]);

    Cache::put(FindMtgoLogPath::CACHE_KEY, collect(['stale']), 5);

    $result = DetectStaleIngest::run();

    expect($result)->toBeTrue();
    expect(Cache::get(FindMtgoLogPath::CACHE_KEY))->toBeNull();
});

it('forgets the path cache when no cursors exist yet but a file is active', function () {
    $logPath = $this->tempDir.'/v1/mtgo.log';
    file_put_contents($logPath, 'hello');
    touch($logPath, time());

    Cache::put(FindMtgoLogPath::CACHE_KEY, collect(['stale']), 5);

    $result = DetectStaleIngest::run();

    expect($result)->toBeTrue();
    expect(Cache::get(FindMtgoLogPath::CACHE_KEY))->toBeNull();
});

it('returns false when log path is unset', function () {
    AppSettings::setLogPath('');

    $result = DetectStaleIngest::run();

    expect($result)->toBeFalse();
});
