<?php

use App\Actions\Matches\ResolveGameResults;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mtgo::shouldReceive('getUsername')->andReturn('TestPlayer');
});

function createResolveMatch(array $overrides = []): MtgoMatch
{
    return MtgoMatch::create(array_merge([
        'mtgo_id' => (string) rand(10000, 99999),
        'token' => 'token-'.uniqid(),
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'started_at' => now()->subHour(),
        'ended_at' => now(),
        'state' => MatchState::InProgress,
    ], $overrides));
}

function createResolveGame(MtgoMatch $match, array $overrides = []): Game
{
    return Game::create(array_merge([
        'match_id' => $match->id,
        'mtgo_id' => rand(100000, 999999),
        'started_at' => now()->subMinutes(30),
        'ended_at' => now()->subMinutes(15),
        'won' => null,
    ], $overrides));
}

/**
 * Build decoded_entries for a single game where TestPlayer wins.
 */
function gameEntriesWin(string $tsPrefix = '2026-01-01T00:00'): array
{
    return [
        ['timestamp' => "{$tsPrefix}:00Z", 'message' => '@P@PTestPlayer joined the game'],
        ['timestamp' => "{$tsPrefix}:01Z", 'message' => '@P@POpponent joined the game'],
        ['timestamp' => "{$tsPrefix}:02Z", 'message' => '@PTestPlayer rolled a 6'],
        ['timestamp' => "{$tsPrefix}:03Z", 'message' => '@POpponent rolled a 3'],
        ['timestamp' => "{$tsPrefix}:04Z", 'message' => '@PTestPlayer chooses to play first.'],
        ['timestamp' => "{$tsPrefix}:05Z", 'message' => '@PTestPlayer begins the game with seven cards in hand.'],
        ['timestamp' => "{$tsPrefix}:06Z", 'message' => '@POpponent begins the game with seven cards in hand.'],
        ['timestamp' => "{$tsPrefix}:50Z", 'message' => '@PTestPlayer wins the game.'],
    ];
}

/**
 * Build decoded_entries for a single game where TestPlayer loses.
 */
function gameEntriesLoss(string $tsPrefix = '2026-01-01T00:05'): array
{
    return [
        ['timestamp' => "{$tsPrefix}:00Z", 'message' => '@P@PTestPlayer joined the game'],
        ['timestamp' => "{$tsPrefix}:01Z", 'message' => '@P@POpponent joined the game'],
        ['timestamp' => "{$tsPrefix}:02Z", 'message' => '@PTestPlayer rolled a 4'],
        ['timestamp' => "{$tsPrefix}:03Z", 'message' => '@POpponent rolled a 5'],
        ['timestamp' => "{$tsPrefix}:04Z", 'message' => '@POpponent chooses to play first.'],
        ['timestamp' => "{$tsPrefix}:05Z", 'message' => '@PTestPlayer begins the game with seven cards in hand.'],
        ['timestamp' => "{$tsPrefix}:06Z", 'message' => '@POpponent begins the game with seven cards in hand.'],
        ['timestamp' => "{$tsPrefix}:50Z", 'message' => '@POpponent wins the game.'],
    ];
}

/**
 * Build a full 2-1 match log (win, loss, win) with match score line.
 */
function twoOneMatchEntries(): array
{
    return array_merge(
        gameEntriesWin('2026-01-01T00:00'),
        [['timestamp' => '2026-01-01T00:00:51Z', 'message' => '@PTestPlayer leads the match 1-0.']],
        gameEntriesLoss('2026-01-01T00:05'),
        [['timestamp' => '2026-01-01T00:05:51Z', 'message' => '@POpponent ties the match 1-1.']],
        gameEntriesWin('2026-01-01T00:10'),
        [['timestamp' => '2026-01-01T00:10:51Z', 'message' => '@PTestPlayer wins the match 2-1.']],
    );
}

it('updates Game.won progressively for InProgress match', function () {
    $match = createResolveMatch(['state' => MatchState::InProgress]);
    $game1 = createResolveGame($match, ['started_at' => now()->subMinutes(30), 'won' => null]);
    $game2 = createResolveGame($match, ['started_at' => now()->subMinutes(15), 'won' => null]);

    // Decoded entries show game 1 won (only 1 game finished so far)
    GameLog::create([
        'match_token' => $match->token,
        'file_path' => '/tmp/gamelog.dat',
        'decoded_entries' => gameEntriesWin(),
        'decoded_at' => now(),
        'byte_offset' => 0,
        'decoded_version' => 1,
    ]);

    ResolveGameResults::run();

    expect($game1->fresh()->won)->toBeTrue();
    expect($game2->fresh()->won)->toBeNull(); // no result yet for game 2
});

it('transitions Ended match to Complete when decided', function () {
    $match = createResolveMatch([
        'state' => MatchState::Ended,
        'ended_at' => now(),
    ]);
    createResolveGame($match, ['started_at' => now()->subMinutes(45), 'won' => null]);
    createResolveGame($match, ['started_at' => now()->subMinutes(30), 'won' => null]);
    createResolveGame($match, ['started_at' => now()->subMinutes(15), 'won' => null]);

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => '/tmp/gamelog.dat',
        'decoded_entries' => twoOneMatchEntries(),
        'decoded_at' => now(),
        'byte_offset' => 0,
        'decoded_version' => 1,
    ]);

    ResolveGameResults::run();

    $match->refresh();
    expect($match->state)->toBe(MatchState::Complete);
    expect($match->outcome)->toBe(MatchOutcome::Win);
});

it('transitions Ended match to PendingResult after grace period', function () {
    $match = createResolveMatch([
        'state' => MatchState::Ended,
        'ended_at' => now()->subMinutes(3),
    ]);
    createResolveGame($match, ['started_at' => now()->subMinutes(30), 'won' => null]);

    // Only 1 game result, no match score — DetermineMatchResult will not decide
    GameLog::create([
        'match_token' => $match->token,
        'file_path' => '/tmp/gamelog.dat',
        'decoded_entries' => gameEntriesWin(),
        'decoded_at' => now(),
        'byte_offset' => 0,
        'decoded_version' => 1,
    ]);

    ResolveGameResults::run();

    $match->refresh();
    expect($match->state)->toBe(MatchState::PendingResult);
});

it('completes InProgress match when game log has decisive results', function () {
    $match = createResolveMatch(['state' => MatchState::InProgress]);
    createResolveGame($match, ['started_at' => now()->subMinutes(45), 'won' => null]);
    createResolveGame($match, ['started_at' => now()->subMinutes(30), 'won' => null]);
    createResolveGame($match, ['started_at' => now()->subMinutes(15), 'won' => null]);

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => '/tmp/gamelog.dat',
        'decoded_entries' => twoOneMatchEntries(),
        'decoded_at' => now(),
        'byte_offset' => 0,
        'decoded_version' => 1,
    ]);

    ResolveGameResults::run();

    $match->refresh();
    expect($match->state)->toBe(MatchState::Complete);
    expect($match->outcome)->toBe(MatchOutcome::Win);
    expect($match->ended_at)->not->toBeNull();

    $games = $match->games()->orderBy('started_at')->get();
    expect($games[0]->won)->toBeTrue();
    expect($games[1]->won)->toBeFalse();
    expect($games[2]->won)->toBeTrue();
});

it('completes InProgress match on 2-0 sweep from game log', function () {
    $match = createResolveMatch(['state' => MatchState::InProgress]);
    createResolveGame($match, ['started_at' => now()->subMinutes(30), 'won' => null]);
    createResolveGame($match, ['started_at' => now()->subMinutes(15), 'won' => null]);

    $entries = array_merge(
        gameEntriesWin('2026-01-01T00:00'),
        [['timestamp' => '2026-01-01T00:00:51Z', 'message' => '@PTestPlayer leads the match 1-0.']],
        gameEntriesWin('2026-01-01T00:05'),
        [['timestamp' => '2026-01-01T00:05:51Z', 'message' => '@PTestPlayer wins the match 2-0.']],
    );

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => '/tmp/gamelog.dat',
        'decoded_entries' => $entries,
        'decoded_at' => now(),
        'byte_offset' => 0,
        'decoded_version' => 1,
    ]);

    ResolveGameResults::run();

    $match->refresh();
    expect($match->state)->toBe(MatchState::Complete);
    expect($match->outcome)->toBe(MatchOutcome::Win);

    $games = $match->games()->orderBy('started_at')->get();
    expect($games[0]->won)->toBeTrue();
    expect($games[1]->won)->toBeTrue();
});

it('does not complete InProgress match when game log has partial results', function () {
    $match = createResolveMatch(['state' => MatchState::InProgress]);
    createResolveGame($match, ['started_at' => now()->subMinutes(30), 'won' => null]);
    createResolveGame($match, ['started_at' => now()->subMinutes(15), 'won' => null]);

    // Only 1 game result, no match score — not decisive
    GameLog::create([
        'match_token' => $match->token,
        'file_path' => '/tmp/gamelog.dat',
        'decoded_entries' => gameEntriesWin(),
        'decoded_at' => now(),
        'byte_offset' => 0,
        'decoded_version' => 1,
    ]);

    ResolveGameResults::run();

    $match->refresh();
    expect($match->state)->toBe(MatchState::InProgress);
    // But game 1 result should still be updated
    $games = $match->games()->orderBy('started_at')->get();
    expect($games[0]->won)->toBeTrue();
    expect($games[1]->won)->toBeNull();
});

it('does not complete InProgress match on "leads the match" score line', function () {
    $match = createResolveMatch(['state' => MatchState::InProgress]);
    createResolveGame($match, ['started_at' => now()->subMinutes(30), 'won' => null]);
    createResolveGame($match, ['started_at' => now()->subMinutes(15), 'won' => null]);

    // Game 1 won with "leads the match 1-0" — not a terminal signal
    $entries = array_merge(
        gameEntriesWin('2026-01-01T00:00'),
        [['timestamp' => '2026-01-01T00:00:51Z', 'message' => '@PTestPlayer leads the match 1-0.']],
    );

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => '/tmp/gamelog.dat',
        'decoded_entries' => $entries,
        'decoded_at' => now(),
        'byte_offset' => 0,
        'decoded_version' => 1,
    ]);

    ResolveGameResults::run();

    $match->refresh();
    expect($match->state)->toBe(MatchState::InProgress);
    expect($match->games()->orderBy('started_at')->first()->won)->toBeTrue();
});

it('skips matches without a GameLog', function () {
    createResolveMatch(['state' => MatchState::InProgress]);

    // No GameLog exists — should not throw
    ResolveGameResults::run();

    expect(true)->toBeTrue();
});

it('is idempotent', function () {
    $match = createResolveMatch([
        'state' => MatchState::Ended,
        'ended_at' => now(),
    ]);
    createResolveGame($match, ['started_at' => now()->subMinutes(45), 'won' => null]);
    createResolveGame($match, ['started_at' => now()->subMinutes(30), 'won' => null]);
    createResolveGame($match, ['started_at' => now()->subMinutes(15), 'won' => null]);

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => '/tmp/gamelog.dat',
        'decoded_entries' => twoOneMatchEntries(),
        'decoded_at' => now(),
        'byte_offset' => 0,
        'decoded_version' => 1,
    ]);

    ResolveGameResults::run();
    ResolveGameResults::run(); // second run — match is now Complete, won't be queried

    $match->refresh();
    expect($match->state)->toBe(MatchState::Complete);
});
