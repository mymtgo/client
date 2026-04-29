<?php

use App\Actions\Matches\DetermineMatchResult;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<int, array{0: string, 1: ?string}>  $games  [winner, loser] pairs
 * @return array<int, array{winner: ?string, loser: ?string}>
 */
function games(array $games): array
{
    return array_map(fn ($g) => ['winner' => $g[0], 'loser' => $g[1] ?? null], $games);
}

it('does not inflate results on concession', function () {
    $stateChanges = collect([
        LogEvent::create([
            'file_path' => '/tmp/test.log',
            'byte_offset_start' => 0,
            'byte_offset_end' => 100,
            'timestamp' => now(),
            'level' => 'INFO',
            'category' => 'MatchPlugin',
            'context' => 'LeagueMatchConcedeReqState to LeagueMatchNotJoinedCatchAllState',
            'raw_text' => 'test',
            'ingested_at' => now(),
            'logged_at' => now(),
            'event_type' => 'match_state_changed',
        ]),
    ]);

    $result = DetermineMatchResult::run(
        games: games([['me', 'opp']]),
        localPlayer: 'me',
        stateChanges: $stateChanges,
    );

    expect($result['wins'])->toBe(1)
        ->and($result['losses'])->toBe(0)
        ->and($result['decided'])->toBeTrue();
});

it('marks decided when win threshold reached', function () {
    $result = DetermineMatchResult::run(
        games: games([['me', 'opp'], ['opp', 'me'], ['me', 'opp']]),
        localPlayer: 'me',
        stateChanges: collect(),
    );

    expect($result['wins'])->toBe(2)
        ->and($result['losses'])->toBe(1)
        ->and($result['decided'])->toBeTrue();
});

it('marks decided when match score present', function () {
    $result = DetermineMatchResult::run(
        games: games([['me', 'opp']]),
        localPlayer: 'me',
        stateChanges: collect(),
        matchScoreExists: true,
    );

    expect($result['decided'])->toBeTrue();
});

it('marks not decided when no signal exists', function () {
    $result = DetermineMatchResult::run(
        games: games([['me', 'opp']]),
        localPlayer: 'me',
        stateChanges: collect(),
    );

    expect($result['wins'])->toBe(1)
        ->and($result['losses'])->toBe(0)
        ->and($result['decided'])->toBeFalse();
});

it('marks decided on disconnect', function () {
    $result = DetermineMatchResult::run(
        games: games([['me', 'opp']]),
        localPlayer: 'me',
        stateChanges: collect(),
        disconnectDetected: true,
    );

    expect($result['decided'])->toBeTrue();
});

it('prefers MTGO match score over counted winners', function () {
    $result = DetermineMatchResult::run(
        games: games([['me', 'opp']]),
        localPlayer: 'me',
        stateChanges: collect(),
        matchScore: [2, 1],
        matchScoreExists: true,
    );

    expect($result['wins'])->toBe(2)
        ->and($result['losses'])->toBe(1)
        ->and($result['decided'])->toBeTrue();
});

it('counts only games with winners', function () {
    $result = DetermineMatchResult::run(
        games: [
            ['winner' => 'me', 'loser' => 'opp'],
            ['winner' => null, 'loser' => null],
            ['winner' => 'opp', 'loser' => 'me'],
        ],
        localPlayer: 'me',
        stateChanges: collect(),
    );

    expect($result['wins'])->toBe(1)
        ->and($result['losses'])->toBe(1);
});
