<?php

use App\Jobs\MatchAndScoreJob;
use App\Jobs\ParseAndFilterHistoryJob;
use App\Models\DeckVersion;
use App\Models\ImportScan;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->scan = ImportScan::create([
        'deck_version_id' => DeckVersion::factory()->create()->id,
        'status' => 'processing',
        'stage' => 'parsing',
    ]);
});

it('sets total to zero and completes when no history records exist', function () {
    Queue::fake();

    // Mock ParseGameHistory to return empty
    app()->instance('import.history_records', []);

    $job = new ParseAndFilterHistoryJob($this->scan->id);
    $job->handle();

    $this->scan->refresh();
    expect($this->scan->status)->toBe('complete');
    expect($this->scan->total)->toBe(0);

    Queue::assertNotPushed(MatchAndScoreJob::class);
});

it('filters out existing matches by mtgo_id', function () {
    Queue::fake();

    // Create an existing match with mtgo_id 100
    MtgoMatch::factory()->create(['mtgo_id' => 100]);

    // Mock history records: one existing (100), one new (200)
    app()->instance('import.history_records', [
        ['Id' => 100, 'StartTime' => '2026-01-01T00:00:00Z', 'Opponents' => ['Opp'], 'GameWins' => 2, 'GameLosses' => 1, 'MatchWinners' => [], 'MatchLosers' => [], 'GameIds' => [], 'Format' => 'Modern'],
        ['Id' => 200, 'StartTime' => '2026-01-02T00:00:00Z', 'Opponents' => ['Opp2'], 'GameWins' => 1, 'GameLosses' => 2, 'MatchWinners' => [], 'MatchLosers' => [], 'GameIds' => [], 'Format' => 'Modern'],
    ]);

    $job = new ParseAndFilterHistoryJob($this->scan->id);
    $job->handle();

    $this->scan->refresh();
    expect($this->scan->total)->toBe(1);

    Queue::assertPushed(MatchAndScoreJob::class, fn ($job) => $job->scanId === $this->scan->id);
});

it('exits early when scan is cancelled', function () {
    Queue::fake();

    $this->scan->update(['status' => 'cancelled']);

    $job = new ParseAndFilterHistoryJob($this->scan->id);
    $job->handle();

    Queue::assertNotPushed(MatchAndScoreJob::class);
});

it('marks scan as failed via failed method', function () {
    $job = new ParseAndFilterHistoryJob($this->scan->id);
    $job->failed(new RuntimeException('parse error'));

    $this->scan->refresh();
    expect($this->scan->status)->toBe('failed');
    expect($this->scan->error)->toBe('parse error');
});
