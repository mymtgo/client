<?php

use App\Actions\Logs\IngestLog;
use App\Models\LogCursor;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/mtgo_test_'.uniqid();
    mkdir($this->tempDir);
});

afterEach(function () {
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        array_map('unlink', glob($this->tempDir.'/*'));
        rmdir($this->tempDir);
    }
});

it('persists log events and cursor atomically', function () {
    $logPath = $this->tempDir.'/test.log';
    file_put_contents($logPath, implode("\n", [
        '15:04:11 [INF] (SESSION|Start Session) First event.',
        '15:04:12 [INF] (SESSION|Second) Second event.',
        '',
    ]));

    IngestLog::run($logPath);

    // Both events and cursor should be consistent
    expect(LogEvent::count())->toBe(2);
    $cursor = LogCursor::where('file_path', $logPath)->first();
    expect($cursor->byte_offset)->toBe(filesize($logPath));
});

it('handles large batches across multiple 500-row chunks', function () {
    $logPath = $this->tempDir.'/large.log';

    // Generate 650 lines to trigger multiple 500-row chunks
    $lines = [];
    for ($i = 0; $i < 650; $i++) {
        $time = sprintf('15:%02d:%02d', intdiv($i, 60) % 60, $i % 60);
        $lines[] = "{$time} [INF] (SESSION|Event{$i}) Log line number {$i}.";
    }
    file_put_contents($logPath, implode("\n", $lines)."\n");

    IngestLog::run($logPath);

    expect(LogEvent::count())->toBe(650);

    $cursor = LogCursor::where('file_path', $logPath)->first();
    expect($cursor->byte_offset)->toBe(filesize($logPath));
});

it('keeps cursor and events consistent after repeated runs', function () {
    $logPath = $this->tempDir.'/append.log';
    file_put_contents($logPath, "15:00:00 [INF] (SESSION|First) Event one.\n");

    IngestLog::run($logPath);
    expect(LogEvent::count())->toBe(1);

    // Append more content
    file_put_contents($logPath, "15:00:01 [INF] (SESSION|Second) Event two.\n15:00:02 [INF] (SESSION|Third) Event three.\n", FILE_APPEND);

    IngestLog::run($logPath);
    expect(LogEvent::count())->toBe(3);

    // Cursor should be at end
    $cursor = LogCursor::where('file_path', $logPath)->first();
    expect($cursor->byte_offset)->toBe(filesize($logPath));

    // Running again should not add duplicates
    IngestLog::run($logPath);
    expect(LogEvent::count())->toBe(3);
});
