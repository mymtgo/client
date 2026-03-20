<?php

use App\Actions\Logs\IngestLog;
use App\Events\MatchEnded;
use App\Models\LogCursor;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

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
    file_put_contents($logPath, "15:04:11 [INF] (Game Management|Match State Changed for aaaa-1111 from X to Y) First event.\n");

    IngestLog::run($logPath);
    $countAfterFirst = LogEvent::count();

    // Append new content (classifiable event so it gets stored)
    file_put_contents($logPath, "15:04:12 [INF] (Game Management|Match State Changed for bbbb-2222 from X to Y) Second event.\n", FILE_APPEND);

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

it('dispatches domain events after ingestion', function () {
    Event::fake();

    $logPath = $this->tempDir.'/test.log';
    copy(base_path('tests/Fixtures/sample_log.txt'), $logPath);

    IngestLog::run($logPath);

    // The fixture contains a match_state_changed event with TournamentMatchClosedState
    // which should dispatch MatchEnded.
    Event::assertDispatched(MatchEnded::class);
});

it('does not dispatch domain events when no new events are ingested', function () {
    $logPath = $this->tempDir.'/test.log';
    copy(base_path('tests/Fixtures/sample_log.txt'), $logPath);

    // First run to advance the cursor to EOF
    IngestLog::run($logPath);

    Event::fake([MatchEnded::class]);

    // Second run — cursor is at EOF, no new events
    IngestLog::run($logPath);

    Event::assertNotDispatched(MatchEnded::class);
});

it('handles incomplete events at end of file', function () {
    $logPath = $this->tempDir.'/test.log';
    // Write a complete classifiable event + an incomplete one (no newline at end)
    file_put_contents($logPath, "15:04:11 [INF] (Game Management|Match State Changed for cccc-3333 from X to Y) Complete event.\n15:04:12 [INF] (Game Management|Match State Changed for dddd-4444 from X to Y) This has no newline");

    IngestLog::run($logPath);

    // Should only process the complete event
    $events = LogEvent::all();
    expect($events)->toHaveCount(1);
    expect($events->first()->match_token)->toBe('cccc-3333');
});
