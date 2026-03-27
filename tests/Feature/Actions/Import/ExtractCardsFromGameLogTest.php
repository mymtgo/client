<?php

use App\Actions\Import\ExtractCardsFromGameLog;
use App\Actions\Matches\ParseGameLogBinary;

it('extracts cards for each player from game log entries', function () {
    $raw = file_get_contents(base_path('tests/fixtures/gamelogs/clean_2_0_win.dat'));
    $parsed = ParseGameLogBinary::run($raw);
    $entries = $parsed['entries'];

    $result = ExtractCardsFromGameLog::run($entries);

    expect($result)->toHaveKeys(['players', 'cards_by_player']);
    expect($result['players'])->toBeArray()->not->toBeEmpty();

    foreach ($result['players'] as $player) {
        expect($result['cards_by_player'][$player])->toBeArray();
    }

    $firstPlayer = $result['players'][0];
    $firstCard = $result['cards_by_player'][$firstPlayer][0] ?? null;
    expect($firstCard)->not->toBeNull();
    expect($firstCard)->toHaveKeys(['mtgo_id', 'name']);
    expect($firstCard['mtgo_id'])->toBeInt();
    expect($firstCard['name'])->toBeString()->not->toBeEmpty();
});

it('returns empty cards for instant concede games', function () {
    $raw = file_get_contents(base_path('tests/fixtures/gamelogs/instant_concede.dat'));
    $parsed = ParseGameLogBinary::run($raw);
    $entries = $parsed['entries'];

    $result = ExtractCardsFromGameLog::run($entries);

    expect($result['players'])->toBeArray();
});

it('deduplicates cards by mtgo_id per player', function () {
    $raw = file_get_contents(base_path('tests/fixtures/gamelogs/clean_2_1_win.dat'));
    $parsed = ParseGameLogBinary::run($raw);
    $entries = $parsed['entries'];

    $result = ExtractCardsFromGameLog::run($entries);

    foreach ($result['players'] as $player) {
        $mtgoIds = array_column($result['cards_by_player'][$player], 'mtgo_id');
        expect($mtgoIds)->toBe(array_unique($mtgoIds));
    }
});
