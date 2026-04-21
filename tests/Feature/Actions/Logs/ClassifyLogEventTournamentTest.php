<?php

use App\Actions\Logs\ClassifyLogEvent;
use App\Enums\LogEventType;
use App\Models\LogEvent;

function makeLogEvent(string $rawText, string $category = 'Tournament', string $context = ''): LogEvent
{
    return (new LogEvent)->fill([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => strlen($rawText),
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => $category,
        'context' => $context,
        'raw_text' => $rawText,
        'ingested_at' => now(),
        'logged_at' => now(),
    ]);
}

it('classifies EventSyncData_t blocks as tournament_sync', function () {
    $raw = '12:34:56 [INF] (Tournament|Sync) EventSyncData_t in TournamentUninitializedState {"Token":"4b92a89a-a319-4725-aa5a-35bff1357ec9","Foo":1}';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_SYNC->value);
    expect($event->tournament_token)->toBe('4b92a89a-a319-4725-aa5a-35bff1357ec9');
});

it('classifies Tournament State Changed lines as tournament_state_changed', function () {
    $raw = '15:43:18 [INF] (Tournament|Transition) Token=4b92a89a-a319-4725-aa5a-35bff1357ec9 Tournament State Changed from TournamentUninitializedState to TournamentNotJoinedRoundInProgressState';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_STATE_CHANGED->value);
    expect($event->tournament_token)->toBe('4b92a89a-a319-4725-aa5a-35bff1357ec9');
});

it('leaves tournament_token null when the state change line has no token', function () {
    $raw = '15:43:18 [INF] (Tournament|Transition) Tournament State Changed from X to Y';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_STATE_CHANGED->value);
    expect($event->tournament_token)->toBeNull();
});

it('classifies FlsTournamentRoundResultMessage as tournament_round_result', function () {
    $raw = '19:35:39 [INF] (Tournament|Round) FlsTournamentRoundResultMessage {"Token":"4b92a89a-a319-4725-aa5a-35bff1357ec9","Round":3,"OpponentResults":[]}';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_ROUND_RESULT->value);
    expect($event->tournament_token)->toBe('4b92a89a-a319-4725-aa5a-35bff1357ec9');
});

it('classifies FlsTournamentRoundInfoMessage as tournament_round_info', function () {
    $raw = '19:31:20 [INF] (Tournament|Round) FlsTournamentRoundInfoMessage {"Token":"4b92a89a-a319-4725-aa5a-35bff1357ec9","Round":{"Number":3,"Matches":[]}}';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_ROUND_INFO->value);
    expect($event->tournament_token)->toBe('4b92a89a-a319-4725-aa5a-35bff1357ec9');
});

it('classifies FlsTournamentPlayerIsEliminatedMessage as tournament_player_eliminated', function () {
    $raw = '19:43:50 [INF] (Tournament|Player) FlsTournamentPlayerIsEliminatedMessage {"Token":"4b92a89a-a319-4725-aa5a-35bff1357ec9","LoginID":964394}';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_PLAYER_ELIMINATED->value);
    expect($event->tournament_token)->toBe('4b92a89a-a319-4725-aa5a-35bff1357ec9');
});

it('classifies tournament end messages', function () {
    $raw = '22:00:00 [INF] (Tournament|End) FlsTournamentEndedMessage {"Token":"4b92a89a-a319-4725-aa5a-35bff1357ec9"}';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_ENDED->value);
    expect($event->tournament_token)->toBe('4b92a89a-a319-4725-aa5a-35bff1357ec9');
});
