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
    // Cleanup temp files
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        array_map('unlink', glob($this->tempDir.'/*'));
        rmdir($this->tempDir);
    }
});

it('ingests log events from a file', function () {
    $logPath = $this->tempDir.'/test.log';
    copy(base_path('tests/Fixtures/sample_log.txt'), $logPath);

    IngestLog::run($logPath);

    expect(LogEvent::count())->toBeGreaterThan(0);
    expect(LogCursor::where('file_path', $logPath)->exists())->toBeTrue();
});

it('extracts username from log', function () {
    $logPath = $this->tempDir.'/test.log';
    copy(base_path('tests/Fixtures/sample_log.txt'), $logPath);

    IngestLog::run($logPath);

    $cursor = LogCursor::where('file_path', $logPath)->first();
    expect($cursor->local_username)->toBe('anticloser');
});

it('tracks cursor position correctly', function () {
    $logPath = $this->tempDir.'/test.log';
    copy(base_path('tests/Fixtures/sample_log.txt'), $logPath);

    IngestLog::run($logPath);

    $cursor = LogCursor::where('file_path', $logPath)->first();
    $fileSize = filesize($logPath);

    expect($cursor->byte_offset)->toBe($fileSize);
});

it('is idempotent - running twice produces same result', function () {
    $logPath = $this->tempDir.'/test.log';
    copy(base_path('tests/Fixtures/sample_log.txt'), $logPath);

    IngestLog::run($logPath);
    $countAfterFirst = LogEvent::count();

    IngestLog::run($logPath);
    $countAfterSecond = LogEvent::count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});

it('detects file truncation and resets cursor', function () {
    $logPath = $this->tempDir.'/test.log';
    copy(base_path('tests/Fixtures/sample_log.txt'), $logPath);

    IngestLog::run($logPath);

    $cursor = LogCursor::where('file_path', $logPath)->first();
    $originalOffset = $cursor->byte_offset;

    // Truncate the file (simulate log rotation)
    file_put_contents($logPath, "15:04:11 [INF] (SESSION|Start Session) New session.\n");

    IngestLog::run($logPath);

    $cursor->refresh();
    // Cursor should have been reset since file is now smaller
    expect($cursor->byte_offset)->toBeLessThan($originalOffset);
});

it('detects file replacement via head hash change', function () {
    $logPath = $this->tempDir.'/test.log';
    copy(base_path('tests/Fixtures/sample_log.txt'), $logPath);

    IngestLog::run($logPath);

    $cursor = LogCursor::where('file_path', $logPath)->first();
    $originalHash = $cursor->head_hash;

    // Replace file content completely (different head)
    file_put_contents($logPath, "16:00:00 [INF] (SESSION|Start Session) Completely different log.\n16:00:01 [INF] (UI|Test) Some event.\n");

    IngestLog::run($logPath);

    $cursor->refresh();
    expect($cursor->head_hash)->not->toBe($originalHash);
});

it('handles non-existent file gracefully', function () {
    IngestLog::run('/nonexistent/path/to/file.log');

    expect(LogEvent::count())->toBe(0);
    expect(LogCursor::count())->toBe(0);
});

it('handles null path gracefully', function () {
    IngestLog::run(null);

    expect(LogEvent::count())->toBe(0);
});

it('processes only new content on subsequent runs', function () {
    $logPath = $this->tempDir.'/test.log';
    file_put_contents($logPath, "15:04:11 [INF] (SESSION|Start Session) First event.\n");

    IngestLog::run($logPath);
    $countAfterFirst = LogEvent::count();

    // Append new content
    file_put_contents($logPath, "15:04:12 [INF] (SESSION|Second) Second event.\n", FILE_APPEND);

    IngestLog::run($logPath);
    $countAfterSecond = LogEvent::count();

    expect($countAfterSecond)->toBe($countAfterFirst + 1);
});

it('classifies match state changed events correctly', function () {
    $logPath = $this->tempDir.'/test.log';
    copy(base_path('tests/Fixtures/sample_log.txt'), $logPath);

    IngestLog::run($logPath);

    $matchEvent = LogEvent::where('event_type', 'match_state_changed')->first();

    expect($matchEvent)->not->toBeNull()
        ->and($matchEvent->match_token)->toBe('1b60d302-5391-4947-bff5-70a8108dc509');
});

it('handles incomplete events at end of file', function () {
    $logPath = $this->tempDir.'/test.log';
    // Write an incomplete event (no newline at end)
    file_put_contents($logPath, "15:04:11 [INF] (SESSION|Start Session) Complete event.\n15:04:12 [INF] (SESSION|Incomplete) This has no newline");

    IngestLog::run($logPath);

    // Should only process the complete event
    $events = LogEvent::all();
    expect($events)->toHaveCount(1);
    expect($events->first()->context)->toBe('Start Session');
});
