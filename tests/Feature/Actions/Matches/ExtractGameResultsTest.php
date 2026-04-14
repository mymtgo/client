<?php

use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameLogBinary;

function parseFixture(string $name): array
{
    $raw = file_get_contents(base_path("tests/fixtures/gamelogs/{$name}"));

    return ParseGameLogBinary::run($raw)['entries'];
}

/*
|--------------------------------------------------------------------------
| Clean Win Scenarios
|--------------------------------------------------------------------------
*/

it('extracts a clean 2-0 win', function () {
    $entries = parseFixture('clean_2_0_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['results'])->toBe([true, true]);
    expect($result['games'])->toHaveCount(2);
    expect($result['games'][0]['winner'])->toBe('anticloser');
    expect($result['games'][0]['end_reason'])->toBe('win');
    expect($result['games'][1]['winner'])->toBe('anticloser');
    expect($result['match_score'])->toBe([2, 0]);
});

it('extracts a 2-1 win', function () {
    $entries = parseFixture('clean_2_1_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['results'])->toBe([true, false, true]);
    expect($result['games'])->toHaveCount(3);
    expect($result['match_score'])->toBe([2, 1]);
});

it('extracts a 2-1 loss', function () {
    $entries = parseFixture('clean_2_1_loss.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['results'])->toBe([true, false, false]);
    expect($result['match_score'])->toBe([1, 2]);
});

/*
|--------------------------------------------------------------------------
| Concede / Disconnect Scenarios
|--------------------------------------------------------------------------
*/

it('extracts results with concedes', function () {
    $entries = parseFixture('concede_2_0.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['results'])->toBe([true, true]);
    expect($result['games'][0]['end_reason'])->toBeIn(['win', 'concede']);
    expect($result['games'][1]['end_reason'])->toBeIn(['win', 'concede']);
});

it('extracts results with disconnect', function () {
    $entries = parseFixture('disconnect_game1.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['results'])->toHaveCount(1);
    expect($result['results'][0])->toBeTrue();
});

it('extracts instant concede', function () {
    $entries = parseFixture('instant_concede.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['results'])->toHaveCount(1);
    expect($result['results'][0])->toBeTrue();
    expect($result['games'][0]['end_reason'])->toBeIn(['win', 'concede']);
});

/*
|--------------------------------------------------------------------------
| Metadata Extraction
|--------------------------------------------------------------------------
*/

it('extracts on-play information', function () {
    $entries = parseFixture('clean_2_0_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['on_play'])->toHaveCount(2);
    foreach ($result['on_play'] as $val) {
        expect($val)->toBeBool();
    }
});

it('extracts starting hand sizes', function () {
    $entries = parseFixture('clean_2_0_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['starting_hands'])->not->toBeEmpty();
    foreach ($result['starting_hands'] as $hand) {
        expect($hand)->toHaveKeys(['player', 'starting_hand']);
        expect($hand['starting_hand'])->toBeInt();
        expect($hand['starting_hand'])->toBeBetween(1, 7);
    }
});

it('extracts player names', function () {
    $entries = parseFixture('clean_2_0_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['players'])->toHaveCount(2);
    expect($result['players'])->toContain('anticloser');
});

it('provides per-game timestamps', function () {
    $entries = parseFixture('clean_2_0_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    foreach ($result['games'] as $game) {
        expect($game)->toHaveKeys(['started_at', 'ended_at']);
        expect($game['started_at'])->not->toBeNull();
    }
});

/*
|--------------------------------------------------------------------------
| Large File
|--------------------------------------------------------------------------
*/

it('handles large multi-game files', function () {
    $entries = parseFixture('large_2_0_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['results'])->toBe([true, true]);
    expect($result['match_score'])->toBe([2, 0]);
});

/*
|--------------------------------------------------------------------------
| Edge Cases
|--------------------------------------------------------------------------
*/

it('returns player names without @P prefix', function () {
    $entries = parseFixture('clean_2_0_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    foreach ($result['players'] as $player) {
        expect($player)->not->toStartWith('@');
    }
    foreach ($result['games'] as $game) {
        if ($game['winner']) {
            expect($game['winner'])->not->toStartWith('@');
        }
    }
});

it('provides on_play entry for each game', function () {
    $entries = parseFixture('clean_2_1_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect(count($result['on_play']))->toBeLessThanOrEqual(count($result['games']));
    expect(count($result['on_play']))->toBeGreaterThan(0);
});

/*
|--------------------------------------------------------------------------
| Hyphenated Usernames
|--------------------------------------------------------------------------
*/

it('detects players with hyphens in usernames', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@PBruh-Ket rolled a 5.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@Panticloser rolled a 3.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PBruh-Ket joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Panticloser joined the game.'],
    ];

    $players = ExtractGameResults::detectPlayers($entries);

    expect($players)->toContain('Bruh-Ket');
    expect($players)->toContain('anticloser');
});

it('extracts game results for players with hyphens', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PBruh-Ket joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Panticloser joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PBruh-Ket chooses to play first.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@PBruh-Ket begins the game with seven cards in hand.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Panticloser begins the game with seven cards in hand.'],
        ['timestamp' => '2026-01-01T00:00:03+00:00', 'message' => '@Panticloser wins the game.'],
    ];

    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['games'][0]['winner'])->toBe('anticloser');
    expect($result['games'][0]['on_play'])->toBe('Bruh-Ket');
    expect($result['games'][0]['starting_hands'])->toHaveKey('Bruh-Ket');
});
