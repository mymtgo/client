<?php

use App\Actions\Import\MatchGameLogToHistory;

it('matches history records to game log files by time and opponent', function () {
    // Set up a temp directory with game log fixtures named as Match_GameLog_*.dat
    $tmpDir = sys_get_temp_dir().'/mtgo_test_'.uniqid();
    mkdir($tmpDir);

    $fixtures = [
        'token-aaa' => 'clean_2_0_win.dat',    // anticloser vs Bordas99, 2025-12-17T13:42:33
        'token-bbb' => 'clean_2_1_win.dat',     // anticloser vs NorinTheScary, 2026-01-13T15:35:42
        'token-ccc' => 'instant_concede.dat',    // anticloser vs SilverrHaze, 2025-10-27T16:50:26
    ];

    foreach ($fixtures as $token => $fixture) {
        copy(
            base_path("tests/fixtures/gamelogs/{$fixture}"),
            "{$tmpDir}/Match_GameLog_{$token}.dat"
        );
    }

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

    $result = MatchGameLogToHistory::run($historyRecords, $tmpDir);

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

    // Cleanup
    array_map('unlink', glob("{$tmpDir}/*"));
    rmdir($tmpDir);
});

it('returns unmatched results when no game logs exist', function () {
    $tmpDir = sys_get_temp_dir().'/mtgo_test_empty_'.uniqid();
    mkdir($tmpDir);

    $records = [
        ['Id' => 2001, 'StartTime' => '2025-01-01T12:00:00Z', 'Opponents' => ['Someone']],
    ];

    $result = MatchGameLogToHistory::run($records, $tmpDir);

    expect($result)->toHaveCount(1);
    expect($result[0]['game_log_token'])->toBeNull();

    rmdir($tmpDir);
});
