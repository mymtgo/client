<?php

use App\Actions\Matches\TransitionMatchState;
use App\Enums\MatchState;

it('advances InProgress to Ended on a TournamentMatchClosedState signal', function () {
    expect(TransitionMatchState::run(MatchState::InProgress, 'TournamentMatchClosedState'))
        ->toBe(MatchState::Ended);
});

it('advances InProgress to Ended on a MatchCompletedState signal', function () {
    expect(TransitionMatchState::run(MatchState::InProgress, 'MatchCompletedState'))
        ->toBe(MatchState::Ended);
});

it('advances InProgress to Ended on a MatchEndedState signal', function () {
    expect(TransitionMatchState::run(MatchState::InProgress, 'MatchEndedState'))
        ->toBe(MatchState::Ended);
});

it('advances InProgress to Ended on a MatchClosedState signal', function () {
    expect(TransitionMatchState::run(MatchState::InProgress, 'MatchClosedState'))
        ->toBe(MatchState::Ended);
});

it('advances InProgress to Ended on a JoinedCompletedState signal', function () {
    expect(TransitionMatchState::run(MatchState::InProgress, 'JoinedCompletedState'))
        ->toBe(MatchState::Ended);
});

it('matches a fragment inside a wider context string', function () {
    expect(TransitionMatchState::run(MatchState::InProgress, 'Match|MatchClosedState'))
        ->toBe(MatchState::Ended);
});

it('returns null for an unknown next-state name when InProgress', function () {
    expect(TransitionMatchState::run(MatchState::InProgress, 'SomeFutureStateName'))->toBeNull();
});

it('returns null when current state is already Ended', function () {
    expect(TransitionMatchState::run(MatchState::Ended, 'MatchClosedState'))->toBeNull();
});

it('returns null when current state is Complete', function () {
    expect(TransitionMatchState::run(MatchState::Complete, 'MatchClosedState'))->toBeNull();
});

it('detects join signals via isJoinSignal', function () {
    expect(TransitionMatchState::isJoinSignal('Match|MatchJoinedEventUnderwayState'))->toBeTrue();
    expect(TransitionMatchState::isJoinSignal('Match|MatchClosedState'))->toBeFalse();
    expect(TransitionMatchState::isJoinSignal(null))->toBeFalse();
});

it('detects end signals via isEndSignal', function () {
    expect(TransitionMatchState::isEndSignal('Match|MatchClosedState'))->toBeTrue();
    expect(TransitionMatchState::isEndSignal('Match|TournamentMatchClosedState'))->toBeTrue();
    expect(TransitionMatchState::isEndSignal('Match|MatchJoinedEventUnderwayState'))->toBeFalse();
    expect(TransitionMatchState::isEndSignal(null))->toBeFalse();
});
