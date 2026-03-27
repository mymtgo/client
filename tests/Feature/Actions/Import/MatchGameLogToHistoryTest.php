<?php

use App\Actions\Import\MatchGameLogToHistory;
use App\Models\GameLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $fixtureDir = base_path('tests/fixtures/gamelogs');
    $this->fixturesAvailable = file_exists("{$fixtureDir}/clean_2_0_win.dat")
        && file_exists("{$fixtureDir}/clean_2_1_win.dat")
        && file_exists("{$fixtureDir}/instant_concede.dat");
});

it('matches history records to game log DB records by time and opponent', function () {
    if (! $this->fixturesAvailable) {
        $this->markTestSkipped('MTGO game log fixtures not available.');
    }

    $fixtureDir = base_path('tests/fixtures/gamelogs');

    // Create GameLog DB records pointing to fixture files (no associated match)
    GameLog::create(['match_token' => 'token-aaa', 'file_path' => "{$fixtureDir}/clean_2_0_win.dat"]);
    GameLog::create(['match_token' => 'token-bbb', 'file_path' => "{$fixtureDir}/clean_2_1_win.dat"]);
    GameLog::create(['match_token' => 'token-ccc', 'file_path' => "{$fixtureDir}/instant_concede.dat"]);

    // Build history records that should match by time (±5 min) and opponent
    $historyRecords = [
        [
            'Id' => 1001,
            'StartTime' => '2025-12-17T13:43:00Z', // ~27s after clean_2_0_win
            'Opponents' => ['Bordas99'],
        ],
        [
            'Id' => 1002,
            'StartTime' => '2026-01-13T15:36:00Z', // ~18s after clean_2_1_win
            'Opponents' => ['NorinTheScary'],
        ],
        [
            'Id' => 1003,
            'StartTime' => '2025-10-27T16:51:00Z', // ~34s after instant_concede
            'Opponents' => ['SilverrHaze'],
        ],
        [
            'Id' => 1004,
            'StartTime' => '2025-06-01T12:00:00Z', // no matching game log
            'Opponents' => ['UnknownPlayer'],
        ],
    ];

    $result = MatchGameLogToHistory::run($historyRecords);

    expect($result)->toHaveCount(4);

    // First 3 should match
    expect($result[0]['history_id'])->toBe(1001);
    expect($result[0]['game_log_token'])->toBe('token-aaa');
    expect($result[0]['game_log_entries'])->toBeArray()->not->toBeEmpty();

    expect($result[1]['history_id'])->toBe(1002);
    expect($result[1]['game_log_token'])->toBe('token-bbb');

    expect($result[2]['history_id'])->toBe(1003);
    expect($result[2]['game_log_token'])->toBe('token-ccc');

    // 4th should not match
    expect($result[3]['history_id'])->toBe(1004);
    expect($result[3]['game_log_token'])->toBeNull();
    expect($result[3]['game_log_entries'])->toBeNull();
});

it('returns unmatched results when no game logs exist', function () {
    $records = [
        ['Id' => 2001, 'StartTime' => '2025-01-01T12:00:00Z', 'Opponents' => ['Someone']],
    ];

    $result = MatchGameLogToHistory::run($records);

    expect($result)->toHaveCount(1);
    expect($result[0]['game_log_token'])->toBeNull();
});
