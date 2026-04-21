<?php

use App\Actions\Tournaments\ExtractTournamentPayload;
use App\Enums\LogEventType;
use App\Models\LogEvent;

function makeTournamentLogEvent(string $eventType, string $rawText): LogEvent
{
    return (new LogEvent)->fill([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => strlen($rawText),
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Tournament',
        'context' => '',
        'raw_text' => $rawText,
        'ingested_at' => now(),
        'logged_at' => now(),
        'event_type' => $eventType,
    ]);
}

it('extracts JSON payload for round_result events', function () {
    $event = makeTournamentLogEvent(
        LogEventType::TOURNAMENT_ROUND_RESULT->value,
        '19:35:39 [INF] (Tournament|Round) FlsTournamentRoundResultMessage {"Token":"abc","Round":3,"OpponentResults":[{"LoginID":1,"Win":2}]}'
    );

    $payload = ExtractTournamentPayload::run($event);

    expect($payload)->toMatchArray([
        'Token' => 'abc',
        'Round' => 3,
    ]);
    expect($payload['OpponentResults'])->toBeArray();
});

it('extracts from/to for tournament_state_changed', function () {
    $event = makeTournamentLogEvent(
        LogEventType::TOURNAMENT_STATE_CHANGED->value,
        '15:43:18 [INF] (Tournament|Transition) Token=abc Tournament State Changed from TournamentUninitializedState to TournamentNotJoinedRoundInProgressState'
    );

    $payload = ExtractTournamentPayload::run($event);

    expect($payload)->toMatchArray([
        'from' => 'TournamentUninitializedState',
        'to' => 'TournamentNotJoinedRoundInProgressState',
    ]);
});

it('extracts from/to for tournament_match_state_changed', function () {
    $event = makeTournamentLogEvent(
        LogEventType::TOURNAMENT_MATCH_STATE_CHANGED->value,
        '18:12:05 [INF] (Tournament|MatchTransition) TournamentMatch State Changed for 459eabbd-84b0-4549-a499-d53499350926 from MatchInProgressState to MatchCompleteState'
    );

    $payload = ExtractTournamentPayload::run($event);

    expect($payload)->toMatchArray([
        'match_token' => '459eabbd-84b0-4549-a499-d53499350926',
        'from' => 'MatchInProgressState',
        'to' => 'MatchCompleteState',
    ]);
});

it('returns empty array when extraction fails', function () {
    $event = makeTournamentLogEvent(
        LogEventType::TOURNAMENT_SYNC->value,
        'something without JSON'
    );

    expect(ExtractTournamentPayload::run($event))->toBe([]);
});
