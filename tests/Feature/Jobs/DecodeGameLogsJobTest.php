<?php

use App\Jobs\DecodeGameLogsJob;
use App\Jobs\ParseAndFilterHistoryJob;
use App\Models\DeckVersion;
use App\Models\GameLog;
use App\Models\ImportScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->scan = ImportScan::create([
        'deck_version_id' => DeckVersion::factory()->create()->id,
        'status' => 'processing',
        'stage' => 'decoding',
    ]);
});

it('skips already decoded logs and dispatches next stage', function () {
    Queue::fake();

    // Already decoded log
    GameLog::create([
        'match_token' => 'ABC123',
        'file_path' => '/fake/path.dat',
        'decoded_entries' => [['timestamp' => '2026-01-01T00:00:00Z', 'message' => 'test']],
        'decoded_at' => now(),
        'first_timestamp' => '2026-01-01T00:00:00Z',
        'players' => ['PlayerA', 'PlayerB'],
    ]);

    $job = new DecodeGameLogsJob($this->scan->id);
    $job->handle();

    $this->scan->refresh();
    // No undecoded logs, so total should be 0
    expect($this->scan->total)->toBe(0);

    Queue::assertPushed(ParseAndFilterHistoryJob::class, fn ($job) => $job->scanId === $this->scan->id);
});

it('backfills first_timestamp and players for decoded logs missing metadata', function () {
    Queue::fake();

    // Decoded log but missing first_timestamp/players (pre-migration data)
    GameLog::create([
        'match_token' => 'ABC123',
        'file_path' => '/fake/path.dat',
        'decoded_entries' => [
            ['timestamp' => '2026-01-15T10:30:00Z', 'message' => '@P@PPlayerA joined the game'],
            ['timestamp' => '2026-01-15T10:30:01Z', 'message' => '@P@PPlayerB joined the game'],
        ],
        'decoded_at' => now(),
        'first_timestamp' => null,
        'players' => null,
    ]);

    $job = new DecodeGameLogsJob($this->scan->id);
    $job->handle();

    $log = GameLog::first();
    expect($log->first_timestamp)->not->toBeNull();
    expect($log->players)->toContain('PlayerA');
    expect($log->players)->toContain('PlayerB');

    Queue::assertPushed(ParseAndFilterHistoryJob::class);
});

it('exits early when scan is cancelled', function () {
    Queue::fake();

    $this->scan->update(['status' => 'cancelled']);

    GameLog::create([
        'match_token' => 'ABC123',
        'file_path' => '/fake/path.dat',
    ]);

    $job = new DecodeGameLogsJob($this->scan->id);
    $job->handle();

    Queue::assertNotPushed(ParseAndFilterHistoryJob::class);
});

it('marks scan as failed via failed method', function () {
    $job = new DecodeGameLogsJob($this->scan->id);
    $job->failed(new RuntimeException('decode error'));

    $this->scan->refresh();
    expect($this->scan->status)->toBe('failed');
    expect($this->scan->error)->toBe('decode error');
});
