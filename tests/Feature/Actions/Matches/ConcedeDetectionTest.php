<?php

use App\Actions\Matches\DetermineMatchResult;
use App\Enums\LogEventType;
use App\Models\LogEvent;
use App\Models\LogInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeStateChangeEvent(string $context): LogEvent
{
    return LogEvent::create([
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => '/tmp/test.log',
        'byte_offset_start' => rand(0, 999999),
        'byte_offset_end' => rand(1000000, 9999999),
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Match',
        'context' => $context,
        'raw_text' => '',
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'logged_at' => now(),
        'match_id' => '99999',
        'match_token' => 'test-token',
        'ingested_at' => now(),
    ]);
}

/**
 * Build a games array where each entry is [winner, loser] pair.
 *
 * @param  array<int, array{0: ?string, 1: ?string}>  $pairs
 * @return array<int, array{winner: ?string, loser: ?string}>
 */
function gamePairs(array $pairs): array
{
    return array_map(fn ($g) => ['winner' => $g[0], 'loser' => $g[1] ?? null], $pairs);
}

const ME = 'me';
const OPP = 'opp';

// ─────────────────────────────────────────────────────────────────────────────
// Normal completed matches — full game results, no early termination
// ─────────────────────────────────────────────────────────────────────────────

it('returns correct result for a normal 2-1 win', function () {
    $result = DetermineMatchResult::run(
        games: gamePairs([[ME, OPP], [OPP, ME], [ME, OPP]]),
        localPlayer: ME,
        stateChanges: collect(),
    );

    expect($result)->toBe(['wins' => 2, 'losses' => 1, 'decided' => true]);
});

it('returns correct result for a normal 2-0 win', function () {
    $result = DetermineMatchResult::run(
        games: gamePairs([[ME, OPP], [ME, OPP]]),
        localPlayer: ME,
        stateChanges: collect(),
    );

    expect($result)->toBe(['wins' => 2, 'losses' => 0, 'decided' => true]);
});

it('returns correct result for a normal 1-2 loss', function () {
    $result = DetermineMatchResult::run(
        games: gamePairs([[OPP, ME], [ME, OPP], [OPP, ME]]),
        localPlayer: ME,
        stateChanges: collect(),
    );

    expect($result)->toBe(['wins' => 1, 'losses' => 2, 'decided' => true]);
});

it('returns correct result for a normal 0-2 loss', function () {
    $result = DetermineMatchResult::run(
        games: gamePairs([[OPP, ME], [OPP, ME]]),
        localPlayer: ME,
        stateChanges: collect(),
    );

    expect($result)->toBe(['wins' => 0, 'losses' => 2, 'decided' => true]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Non-league (casual) concede — uses Match* prefixed states
// ─────────────────────────────────────────────────────────────────────────────

it('detects casual concede as a loss after winning game 1', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from MatchJoinedSideboardingState to MatchConcedeReqState'),
        makeStateChangeEvent('Match State Changed from MatchConcedeReqState to MatchNotJoinedEventUnderwayState'),
        makeStateChangeEvent('Match State Changed from MatchNotJoinedEventUnderwayState to MatchJoinedCompletedState'),
    ]);

    $result = DetermineMatchResult::run(
        games: gamePairs([[ME, OPP]]),
        localPlayer: ME,
        stateChanges: $stateChanges,
    );

    expect($result)->toBe(['wins' => 1, 'losses' => 0, 'decided' => true]);
});

it('detects casual concede as a loss after losing game 1', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from MatchJoinedSideboardingState to MatchConcedeReqState'),
        makeStateChangeEvent('Match State Changed from MatchConcedeReqState to MatchNotJoinedEventUnderwayState'),
    ]);

    $result = DetermineMatchResult::run(
        games: gamePairs([[OPP, ME]]),
        localPlayer: ME,
        stateChanges: $stateChanges,
    );

    expect($result)->toBe(['wins' => 0, 'losses' => 1, 'decided' => true]);
});

// ─────────────────────────────────────────────────────────────────────────────
// League concede — uses LeagueMatch* prefixed states
// ─────────────────────────────────────────────────────────────────────────────

it('detects league concede as a loss after losing game 1', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from LeagueMatchSideboardingDeckAcceptedState to LeagueMatchConcedeReqState'),
        makeStateChangeEvent('Match State Changed from LeagueMatchConcedeReqState to LeagueMatchNotJoinedCatchAllState'),
        makeStateChangeEvent('Match State Changed from LeagueMatchNotJoinedCatchAllState to LeagueMatchClosedState'),
    ]);

    $result = DetermineMatchResult::run(
        games: gamePairs([[OPP, ME]]),
        localPlayer: ME,
        stateChanges: $stateChanges,
    );

    expect($result)->toBe(['wins' => 0, 'losses' => 1, 'decided' => true]);
});

it('detects league concede as a loss after winning game 1', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from LeagueMatchSideboardingDeckAcceptedState to LeagueMatchConcedeReqState'),
        makeStateChangeEvent('Match State Changed from LeagueMatchConcedeReqState to LeagueMatchNotJoinedCatchAllState'),
    ]);

    $result = DetermineMatchResult::run(
        games: gamePairs([[ME, OPP]]),
        localPlayer: ME,
        stateChanges: $stateChanges,
    );

    expect($result)->toBe(['wins' => 1, 'losses' => 0, 'decided' => true]);
});

it('detects league concede with no games played', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from LeagueMatchJoinedEventUnderwayState to LeagueMatchConcedeReqState'),
        makeStateChangeEvent('Match State Changed from LeagueMatchConcedeReqState to LeagueMatchNotJoinedCatchAllState'),
    ]);

    $result = DetermineMatchResult::run(
        games: [],
        localPlayer: ME,
        stateChanges: $stateChanges,
    );

    expect($result)->toBe(['wins' => 0, 'losses' => 0, 'decided' => true]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Opponent disconnect — no ConcedeReq, should award win to local player
// ─────────────────────────────────────────────────────────────────────────────

it('marks decided when opponent disconnects and disconnectDetected is passed', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from MatchJoinedEventUnderwayState to MatchClosedState'),
    ]);

    $result = DetermineMatchResult::run(
        games: gamePairs([[ME, OPP]]),
        localPlayer: ME,
        stateChanges: $stateChanges,
        disconnectDetected: true,
    );

    expect($result)->toBe(['wins' => 1, 'losses' => 0, 'decided' => true]);
});

it('marks decided when opponent disconnects in league match', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from LeagueMatchJoinedEventUnderwayState to LeagueMatchClosedState'),
    ]);

    $result = DetermineMatchResult::run(
        games: gamePairs([[ME, OPP]]),
        localPlayer: ME,
        stateChanges: $stateChanges,
        disconnectDetected: true,
    );

    expect($result)->toBe(['wins' => 1, 'losses' => 0, 'decided' => true]);
});

it('marks decided when opponent disconnects with no games played', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from MatchJoinedEventUnderwayState to MatchClosedState'),
    ]);

    $result = DetermineMatchResult::run(
        games: [],
        localPlayer: ME,
        stateChanges: $stateChanges,
        disconnectDetected: true,
    );

    expect($result)->toBe(['wins' => 0, 'losses' => 0, 'decided' => true]);
});

// ─────────────────────────────────────────────────────────────────────────────
// localPlayerConceded helper
// ─────────────────────────────────────────────────────────────────────────────

it('recognises casual concede transition', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from MatchConcedeReqState to MatchNotJoinedEventUnderwayState'),
    ]);

    expect(DetermineMatchResult::localPlayerConceded($stateChanges))->toBeTrue();
});

it('recognises league concede transition', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from LeagueMatchConcedeReqState to LeagueMatchNotJoinedCatchAllState'),
    ]);

    expect(DetermineMatchResult::localPlayerConceded($stateChanges))->toBeTrue();
});

it('does not flag opponent disconnect as local concede', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from MatchJoinedEventUnderwayState to MatchClosedState'),
    ]);

    expect(DetermineMatchResult::localPlayerConceded($stateChanges))->toBeFalse();
});

it('does not flag normal match end as local concede', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from MatchJoinedCompletedState to MatchCompletedState'),
        makeStateChangeEvent('Match State Changed from MatchCompletedState to MatchClosedState'),
    ]);

    expect(DetermineMatchResult::localPlayerConceded($stateChanges))->toBeFalse();
});
