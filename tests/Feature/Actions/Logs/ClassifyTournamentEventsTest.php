<?php

use App\Actions\Logs\ClassifyLogEvent;
use App\Models\LogEvent;

it('classifies tournament state change events', function () {
    $event = new LogEvent([
        'raw_text' => '18:42:27 [INF] (Game Management|Tournament State Changed for b049851f-3a2b-41e6-9260-ed2100d57071 from TournamentUninitializedState to PremierNotJoinedAwaitingMinPlayersState)',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('tournament_state_changed')
        ->and($result->match_token)->toBe('b049851f-3a2b-41e6-9260-ed2100d57071');
});

it('classifies tournament round result events', function () {
    $event = new LogEvent([
        'raw_text' => '18:43:20 [INF] (Game Management|Processing Registered Handler for FlsTournamentRoundResultMessage in TournamentNotJoinedRoundInProgressState) Processor: TournamentNotJoinedRoundInProgressState Message: {"Token":"18c84071-d8b7-474e-9fdc-efaa08bcf02f","Round":3,"Results":[]}',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('tournament_round_result')
        ->and($result->match_token)->toBe('18c84071-d8b7-474e-9fdc-efaa08bcf02f');
});

it('classifies tournament player elimination events', function () {
    $event = new LogEvent([
        'raw_text' => '19:00:00 [INF] (Game Management|Processing Registered Handler for FlsTournamentPlayerIsEliminatedMessage in TournamentNotJoinedRoundInProgressState) Processor: TournamentNotJoinedRoundInProgressState Message: {"Token":"6eaaa32d-de66-45f8-85b9-cfde3eaa0924","LoginID":829651,"Reason":"Match Loss"}',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('tournament_player_eliminated')
        ->and($result->match_token)->toBe('6eaaa32d-de66-45f8-85b9-cfde3eaa0924');
});

it('classifies tournament end events', function () {
    $event = new LogEvent([
        'raw_text' => '19:07:06 [INF] (Game Management|Processing Registered Handler for FlsTournamentEndRespMessage in TournamentNotJoinedRoundInProgressState) Processor: TournamentNotJoinedRoundInProgressState Message: {"Token":"e63ba74a-50e1-4321-a123-456789abcdef","EndDate":"2026-03-18T19:07:06"}',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('tournament_ended')
        ->and($result->match_token)->toBe('e63ba74a-50e1-4321-a123-456789abcdef');
});

it('classifies tournament sync events', function () {
    $event = new LogEvent([
        'raw_text' => '18:42:28 [INF] (Game Management|Processing Registered Handler for EventSyncData_t in TournamentUninitializedState) Processor: TournamentUninitializedState Message: {"EventToken":"43bd3465-f61e-4d92-bb46-eecae05612d5","EventID":12835954,"Players":[]}',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('tournament_sync')
        ->and($result->match_token)->toBe('43bd3465-f61e-4d92-bb46-eecae05612d5');
});

it('classifies tournament match state change events', function () {
    $event = new LogEvent([
        'raw_text' => '18:45:00 [INF] (Game Management|TournamentMatch State Changed for d7da3580-a227-48b5-b449-22910c7404ea from TournamentMatchUninitializedState to TournamentMatchNotJoinedEventUnderwayState)',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('tournament_match_state_changed')
        ->and($result->match_token)->toBe('d7da3580-a227-48b5-b449-22910c7404ea');
});

it('does not classify regular match state changes as tournament events', function () {
    $event = new LogEvent([
        'raw_text' => '18:42:27 [INF] (Game Management|Match State Changed for abc12345-1234-5678-9012-abcdef123456 from MatchUninitializedState to MatchNotJoinedAwaitingMinPlayersState)',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('match_state_changed');
});
