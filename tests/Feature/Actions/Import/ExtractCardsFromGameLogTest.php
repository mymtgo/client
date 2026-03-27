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

it('returns per-game cards split by game boundaries', function () {
    // clean_2_1_win.dat has 3 games
    $raw = file_get_contents(base_path('tests/fixtures/gamelogs/clean_2_1_win.dat'));
    $parsed = ParseGameLogBinary::run($raw);
    $entries = $parsed['entries'];

    $result = ExtractCardsFromGameLog::run($entries);

    expect($result)->toHaveKey('cards_by_game');
    expect($result['cards_by_game'])->toBeArray();

    // Should have per-game entries (3 games in this fixture)
    expect(count($result['cards_by_game']))->toBeGreaterThanOrEqual(2);

    // Each game should have cards keyed by player
    foreach ($result['cards_by_game'] as $gameCards) {
        expect($gameCards)->toBeArray();
        foreach ($gameCards as $player => $cards) {
            expect($cards)->toBeArray();
            foreach ($cards as $card) {
                expect($card)->toHaveKeys(['mtgo_id', 'name']);
            }
        }
    }

    // Per-game cards should deduplicate within each game
    foreach ($result['cards_by_game'] as $gameCards) {
        foreach ($gameCards as $cards) {
            $mtgoIds = array_column($cards, 'mtgo_id');
            expect($mtgoIds)->toBe(array_unique($mtgoIds));
        }
    }
});
