<?php

use App\Actions\Matches\DetermineMatchResult;
use App\Enums\LogEventType;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeStateChangeEvent(string $context): LogEvent
{
    return LogEvent::create([
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

// ─────────────────────────────────────────────────────────────────────────────
// Normal completed matches — full game results, no early termination
// ─────────────────────────────────────────────────────────────────────────────

it('returns correct result for a normal 2-1 win', function () {
    $result = DetermineMatchResult::run(
        [true, false, true],
        collect(),
    );

    expect($result)->toBe(['wins' => 2, 'losses' => 1]);
});

it('returns correct result for a normal 2-0 win', function () {
    $result = DetermineMatchResult::run(
        [true, true],
        collect(),
    );

    expect($result)->toBe(['wins' => 2, 'losses' => 0]);
});

it('returns correct result for a normal 1-2 loss', function () {
    $result = DetermineMatchResult::run(
        [false, true, false],
        collect(),
    );

    expect($result)->toBe(['wins' => 1, 'losses' => 2]);
});

it('returns correct result for a normal 0-2 loss', function () {
    $result = DetermineMatchResult::run(
        [false, false],
        collect(),
    );

    expect($result)->toBe(['wins' => 0, 'losses' => 2]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Non-league (casual) concede — uses Match* prefixed states
// Based on real match 26: MatchConcedeReqState → MatchNotJoinedEventUnderwayState
// ─────────────────────────────────────────────────────────────────────────────

it('detects casual concede as a loss after winning game 1', function () {
    // Match 26: won game 1 ([true]), then conceded match during sideboarding
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from MatchJoinedSideboardingState to MatchConcedeReqState'),
        makeStateChangeEvent('Match State Changed from MatchConcedeReqState to MatchNotJoinedEventUnderwayState'),
        makeStateChangeEvent('Match State Changed from MatchNotJoinedEventUnderwayState to MatchJoinedCompletedState'),
    ]);

    $result = DetermineMatchResult::run([true], $stateChanges);

    expect($result)->toBe(['wins' => 1, 'losses' => 2]);
});

it('detects casual concede as a loss after losing game 1', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from MatchJoinedSideboardingState to MatchConcedeReqState'),
        makeStateChangeEvent('Match State Changed from MatchConcedeReqState to MatchNotJoinedEventUnderwayState'),
    ]);

    $result = DetermineMatchResult::run([false], $stateChanges);

    expect($result)->toBe(['wins' => 0, 'losses' => 2]);
});

// ─────────────────────────────────────────────────────────────────────────────
// League concede — uses LeagueMatch* prefixed states
// Based on real match 27: LeagueMatchConcedeReqState → LeagueMatchNotJoinedCatchAllState
// ─────────────────────────────────────────────────────────────────────────────

it('detects league concede as a loss after losing game 1', function () {
    // Match 27: lost game 1 ([false]), then conceded match
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from LeagueMatchSideboardingDeckAcceptedState to LeagueMatchConcedeReqState'),
        makeStateChangeEvent('Match State Changed from LeagueMatchConcedeReqState to LeagueMatchNotJoinedCatchAllState'),
        makeStateChangeEvent('Match State Changed from LeagueMatchNotJoinedCatchAllState to LeagueMatchClosedState'),
    ]);

    $result = DetermineMatchResult::run([false], $stateChanges);

    expect($result)->toBe(['wins' => 0, 'losses' => 2]);
});

it('detects league concede as a loss after winning game 1', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from LeagueMatchSideboardingDeckAcceptedState to LeagueMatchConcedeReqState'),
        makeStateChangeEvent('Match State Changed from LeagueMatchConcedeReqState to LeagueMatchNotJoinedCatchAllState'),
    ]);

    $result = DetermineMatchResult::run([true], $stateChanges);

    expect($result)->toBe(['wins' => 1, 'losses' => 2]);
});

it('detects league concede with no games played', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from LeagueMatchJoinedEventUnderwayState to LeagueMatchConcedeReqState'),
        makeStateChangeEvent('Match State Changed from LeagueMatchConcedeReqState to LeagueMatchNotJoinedCatchAllState'),
    ]);

    $result = DetermineMatchResult::run([], $stateChanges);

    expect($result)->toBe(['wins' => 0, 'losses' => 2]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Opponent disconnect — no ConcedeReq, should award win to local player
// ─────────────────────────────────────────────────────────────────────────────

it('awards win when opponent disconnects with no concede events', function () {
    // Match closed without ConcedeReq → opponent quit
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from MatchJoinedEventUnderwayState to MatchClosedState'),
    ]);

    $result = DetermineMatchResult::run([true], $stateChanges);

    expect($result)->toBe(['wins' => 2, 'losses' => 0]);
});

it('awards win when opponent disconnects in league match', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from LeagueMatchJoinedEventUnderwayState to LeagueMatchClosedState'),
    ]);

    $result = DetermineMatchResult::run([true], $stateChanges);

    expect($result)->toBe(['wins' => 2, 'losses' => 0]);
});

it('awards win when opponent disconnects with no games played', function () {
    $stateChanges = collect([
        makeStateChangeEvent('Match State Changed from MatchJoinedEventUnderwayState to MatchClosedState'),
    ]);

    $result = DetermineMatchResult::run([], $stateChanges);

    expect($result)->toBe(['wins' => 2, 'losses' => 0]);
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
