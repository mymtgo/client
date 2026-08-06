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

    expect($result['games'])->toHaveCount(2);
    expect($result['games'][0]['winner'])->toBe('anticloser');
    expect($result['games'][0]['end_reason'])->toBe('win');
    expect($result['games'][1]['winner'])->toBe('anticloser');
    expect($result['match_score'])->toBe([2, 0]);
});

it('extracts a 2-1 win', function () {
    $entries = parseFixture('clean_2_1_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['games'])->toHaveCount(3);
    expect($result['games'][0]['winner'])->toBe('anticloser');
    expect($result['games'][1]['winner'])->not->toBe('anticloser');
    expect($result['games'][2]['winner'])->toBe('anticloser');
    expect($result['match_score'])->toBe([2, 1]);
});

it('extracts a 2-1 loss', function () {
    $entries = parseFixture('clean_2_1_loss.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['games'])->toHaveCount(3);
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

    expect($result['games'])->toHaveCount(2);
    expect($result['games'][0]['winner'])->toBe('anticloser');
    expect($result['games'][1]['winner'])->toBe('anticloser');
    expect($result['games'][0]['end_reason'])->toBeIn(['win', 'concede']);
    expect($result['games'][1]['end_reason'])->toBeIn(['win', 'concede']);
});

it('extracts results with disconnect', function () {
    $entries = parseFixture('disconnect_game1.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['games'])->toHaveCount(1);
    expect($result['games'][0]['winner'])->toBe('anticloser');
});

it('extracts instant concede', function () {
    $entries = parseFixture('instant_concede.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['games'])->toHaveCount(1);
    expect($result['games'][0]['winner'])->toBe('anticloser');
    expect($result['games'][0]['end_reason'])->toBeIn(['win', 'concede']);
});

it('does not fabricate a decisive third game from a between-games disconnect at 1-1', function () {
    // A disconnect is NOT a match-deciding signal — the dropping player may
    // reconnect. At 1-1 a trailing "lost connection" must leave the match
    // undecided; resolution waits for a clear end signal (handled by the
    // stale-match reaper once the match goes quiet).
    $entries = [
        // Game 1 — local player concedes
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@Panticloser rolled a 5.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@Pjanrepuge rolled a 1.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@P@Panticloser joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@P@Pjanrepuge joined the game.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Panticloser has conceded from the game.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Pjanrepuge wins the game.'],
        // Game 2 — opponent concedes
        ['timestamp' => '2026-01-01T00:01:00+00:00', 'message' => '@P@Panticloser joined the game.'],
        ['timestamp' => '2026-01-01T00:01:00+00:00', 'message' => '@P@Pjanrepuge joined the game.'],
        ['timestamp' => '2026-01-01T00:01:02+00:00', 'message' => '@Pjanrepuge has conceded from the game.'],
        ['timestamp' => '2026-01-01T00:01:02+00:00', 'message' => '@Panticloser wins the game.'],
        // Trailing disconnect — no roll/join precedes it, so it is NOT a new game
        ['timestamp' => '2026-01-01T00:02:00+00:00', 'message' => '@Pjanrepuge has lost connection to the game.'],
    ];

    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['games'])->toHaveCount(2);
    expect($result['games'][0]['winner'])->toBe('janrepuge');
    expect($result['games'][1]['winner'])->toBe('anticloser');
});

it('does not declare a game winner from a lone disconnect', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@Panticloser rolled a 5.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@Pjanrepuge rolled a 1.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@P@Panticloser joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@P@Pjanrepuge joined the game.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Pjanrepuge has lost connection to the game.'],
    ];

    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['games'])->toHaveCount(1);
    expect($result['games'][0]['winner'])->toBeNull();
});

it('does not split a single game into two when a player disconnects then reconnects', function () {
    // Disconnect mid-game followed by continued play (reconnect) is ONE game.
    // The disconnect must not act as a game boundary.
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@Panticloser rolled a 5.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@Pjanrepuge rolled a 1.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@P@Panticloser joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@P@Pjanrepuge joined the game.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Pjanrepuge has lost connection to the game.'],
        // Reconnect: same game continues to a real result
        ['timestamp' => '2026-01-01T00:00:30+00:00', 'message' => '@Panticloser wins the game.'],
    ];

    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['games'])->toHaveCount(1);
    expect($result['games'][0]['winner'])->toBe('anticloser');
});

it('counts a local between-games concede at 1-1 as a decisive loss', function () {
    $entries = [
        // Game 1 — opponent concedes
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@Panticloser rolled a 5.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@Pjanrepuge rolled a 1.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@P@Panticloser joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@P@Pjanrepuge joined the game.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Pjanrepuge has conceded from the game.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Panticloser wins the game.'],
        // Game 2 — local player concedes
        ['timestamp' => '2026-01-01T00:01:00+00:00', 'message' => '@P@Panticloser joined the game.'],
        ['timestamp' => '2026-01-01T00:01:00+00:00', 'message' => '@P@Pjanrepuge joined the game.'],
        ['timestamp' => '2026-01-01T00:01:02+00:00', 'message' => '@Panticloser has conceded from the game.'],
        ['timestamp' => '2026-01-01T00:01:02+00:00', 'message' => '@Pjanrepuge wins the game.'],
        // Game 3 — local player drops during sideboarding
        ['timestamp' => '2026-01-01T00:02:00+00:00', 'message' => '@Panticloser has conceded from the game.'],
    ];

    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['games'])->toHaveCount(3);
    expect($result['games'][2]['winner'])->toBe('janrepuge');
    expect($result['games'][2]['loser'])->toBe('anticloser');
    expect($result['games'][2]['end_reason'])->toBe('concede');
});

/*
|--------------------------------------------------------------------------
| Metadata Extraction
|--------------------------------------------------------------------------
*/

it('extracts on-play name per game', function () {
    $entries = parseFixture('clean_2_0_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    foreach ($result['games'] as $game) {
        expect($game['on_play'])->toBeString();
        expect($result['players'])->toContain($game['on_play']);
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

    expect($result['games'])->toHaveCount(2);
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

/*
|--------------------------------------------------------------------------
| Real-world: missing chooses-to-play in some games
|--------------------------------------------------------------------------
| Confirms the per-game array preserves null when a game lacks the
| "chooses to play" line, instead of silently dropping the slot and
| mis-aligning later indices.
*/

it('preserves null on_play per game when source line is missing', function () {
    $entries = parseFixture('multi_game_partial_on_play.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['games'])->toHaveCount(3);
    expect($result['games'][0]['on_play'])->toBe('anticloser');
    expect($result['games'][1]['on_play'])->toBe('anticloser');
    expect($result['games'][2]['on_play'])->toBeNull();

    expect($result['games'][0]['game_index'])->toBe(0);
    expect($result['games'][1]['game_index'])->toBe(1);
    expect($result['games'][2]['game_index'])->toBe(2);
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

/*
|--------------------------------------------------------------------------
| Dotted Usernames
|--------------------------------------------------------------------------
| MTGO usernames may contain periods (e.g. "mr.moo"). A dotted local
| player whose name fails the pattern has every win silently dropped —
| matches they won 2-0 resolve as 0-0 unknown, and matches where the
| opponent took a game resolve as losses.
*/

it('detects players with dots in usernames', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@Pmr.moo rolled a 5.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@PMooFoe rolled a 3.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Pmr.moo joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PMooFoe joined the game.'],
    ];

    $players = ExtractGameResults::detectPlayers($entries);

    expect($players)->toContain('mr.moo');
    expect($players)->toContain('MooFoe');
});

it('credits wins to a local player with a dotted username', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Pmr.moo joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PMooFoe joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@Pmr.moo chooses to play first.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Pmr.moo begins the game with seven cards in hand.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@PMooFoe begins the game with seven cards in hand.'],
        ['timestamp' => '2026-01-01T00:00:03+00:00', 'message' => '@Pmr.moo wins the game.'],
        ['timestamp' => '2026-01-01T00:00:04+00:00', 'message' => '@Pmr.moo rolled a 6.'],
        ['timestamp' => '2026-01-01T00:00:04+00:00', 'message' => '@P@PMooFoe joined the game.'],
        ['timestamp' => '2026-01-01T00:00:05+00:00', 'message' => '@PMooFoe chooses to play first.'],
        ['timestamp' => '2026-01-01T00:00:06+00:00', 'message' => '@Pmr.moo wins the game.'],
        ['timestamp' => '2026-01-01T00:00:07+00:00', 'message' => '@Pmr.moo wins the match 2-0'],
    ];

    $result = ExtractGameResults::run($entries, 'mr.moo');

    expect($result['games'])->toHaveCount(2);
    expect($result['games'][0]['winner'])->toBe('mr.moo');
    expect($result['games'][1]['winner'])->toBe('mr.moo');
    expect($result['games'][0]['on_play'])->toBe('mr.moo');
    expect($result['games'][0]['starting_hands'])->toHaveKey('mr.moo');
    expect($result['match_score'])->toBe([2, 0]);
    expect($result['match_decided'])->toBeTrue();
});

it('credits a concede win to a dotted local player', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Pmr.moo joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PMooFoe joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PMooFoe has conceded from the game.'],
    ];

    $result = ExtractGameResults::run($entries, 'mr.moo');

    expect($result['games'][0]['winner'])->toBe('mr.moo');
    expect($result['games'][0]['end_reason'])->toBe('concede');
});
