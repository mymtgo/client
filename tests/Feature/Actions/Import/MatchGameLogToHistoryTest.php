<?php

use App\Actions\Import\MatchGameLogToHistory;
use App\Actions\Matches\ParseGameHistory;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('matches history records to game log files by time and opponent', function () {
    $dataPath = storage_path('app/91F5DC46A0AFBF283E8FD4E9E184F175');
    $historyPath = $dataPath.'/mtgo_game_history';
    $records = ParseGameHistory::parse($historyPath);

    // Take a small sample
    $sample = array_slice($records, 0, 5);

    $result = MatchGameLogToHistory::run($sample, $dataPath);

    expect($result)->toBeArray();
    expect(count($result))->toBe(count($sample));

    foreach ($result as $item) {
        expect($item)->toHaveKeys(['history_id', 'game_log_token', 'game_log_entries']);
        expect($item['history_id'])->toBeInt();
    }

    // Most should have a game log match
    $matched = array_filter($result, fn ($item) => $item['game_log_token'] !== null);
    expect(count($matched))->toBeGreaterThanOrEqual(3);
});
