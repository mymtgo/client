<?php

use App\Jobs\MatchAndScoreJob;
use App\Models\DeckVersion;
use App\Models\GameLog;
use App\Models\ImportScan;
use App\Models\ImportScanMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->scan = ImportScan::create([
        'deck_version_id' => DeckVersion::factory()->create()->id,
        'status' => 'processing',
        'stage' => 'scoring',
        'total' => 1,
    ]);
});

it('creates import scan matches from history records', function () {
    Queue::fake();

    app()->instance('import.history_records', [
        [
            'Id' => 500,
            'StartTime' => '2026-01-15T10:30:00Z',
            'Opponents' => ['OpponentX'],
            'GameWins' => 2,
            'GameLosses' => 1,
            'MatchWinners' => ['LocalPlayer'],
            'MatchLosers' => ['OpponentX'],
            'GameIds' => [1, 2, 3],
            'GameWinsToWinMatch' => 2,
            'Description' => 'Test match',
            'Round' => 1,
            'Format' => 'Modern',
        ],
    ]);

    $job = new MatchAndScoreJob($this->scan->id);
    $job->handle();

    expect(ImportScanMatch::count())->toBe(1);

    $match = ImportScanMatch::first();
    expect($match->history_id)->toBe(500);
    expect($match->opponent)->toBe('OpponentX');
    expect($match->games_won)->toBe(2);
    expect($match->games_lost)->toBe(1);
    expect($match->outcome)->toBe('win');

    $this->scan->refresh();
    expect($this->scan->status)->toBe('complete');
    expect($this->scan->progress)->toBe(1);
});

it('matches game logs by timestamp and opponent', function () {
    Queue::fake();

    GameLog::create([
        'match_token' => 'TOKEN_ABC',
        'file_path' => '/fake/path.dat',
        'decoded_entries' => [
            ['timestamp' => '2026-01-15T10:31:00Z', 'message' => '@P@PLocalPlayer joined the game'],
            ['timestamp' => '2026-01-15T10:31:01Z', 'message' => '@P@POpponentX joined the game'],
        ],
        'decoded_at' => now(),
        'first_timestamp' => '2026-01-15T10:31:00Z',
        'players' => ['LocalPlayer', 'OpponentX'],
    ]);

    app()->instance('import.history_records', [
        [
            'Id' => 600,
            'StartTime' => '2026-01-15T10:30:00Z',
            'Opponents' => ['OpponentX'],
            'GameWins' => 2,
            'GameLosses' => 0,
            'MatchWinners' => [],
            'MatchLosers' => [],
            'GameIds' => [],
            'GameWinsToWinMatch' => null,
            'Description' => null,
            'Round' => 0,
            'Format' => 'Modern',
        ],
    ]);

    $job = new MatchAndScoreJob($this->scan->id);
    $job->handle();

    $match = ImportScanMatch::first();
    expect($match->game_log_token)->toBe('TOKEN_ABC');
});

it('exits early when scan is cancelled', function () {
    Queue::fake();

    $this->scan->update(['status' => 'cancelled']);

    app()->instance('import.history_records', [
        ['Id' => 700, 'StartTime' => '2026-01-01T00:00:00Z', 'Opponents' => ['Opp'], 'GameWins' => 2, 'GameLosses' => 1, 'MatchWinners' => [], 'MatchLosers' => [], 'GameIds' => [], 'Format' => 'Modern', 'GameWinsToWinMatch' => null, 'Description' => null, 'Round' => 0],
    ]);

    $job = new MatchAndScoreJob($this->scan->id);
    $job->handle();

    expect(ImportScanMatch::count())->toBe(0);
});

it('marks scan as failed via failed method', function () {
    $job = new MatchAndScoreJob($this->scan->id);
    $job->failed(new RuntimeException('score error'));

    $this->scan->refresh();
    expect($this->scan->status)->toBe('failed');
    expect($this->scan->error)->toBe('score error');
});
