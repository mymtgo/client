<?php

use App\Actions\Logs\ClassifyLogEvent;
use App\Enums\LogEventType;
use App\Models\LogEvent;

function makeEventFromFixture(string $filename): LogEvent
{
    $path = base_path("tests/Fixtures/log_samples/{$filename}");
    $raw = trim(file_get_contents($path));

    preg_match(
        '/^(?<time>\d{2}:\d{2}:\d{2}) \[(?<level>\w+)\] \((?<cat>[^|]+)\|(?<ctx>[^\)]*)\)/',
        $raw,
        $m,
    );

    return (new LogEvent)->fill([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => strlen($raw),
        'timestamp' => $m['time'] ?? '00:00:00',
        'level' => $m['level'] ?? 'INF',
        'category' => $m['cat'] ?? '',
        'context' => $m['ctx'] ?? '',
        'raw_text' => $raw,
        'ingested_at' => now(),
        'logged_at' => now(),
    ]);
}

it('classifies tournament_state_changed with tournament_token from context', function () {
    $event = ClassifyLogEvent::run(makeEventFromFixture('tournament_state_changed.txt'));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_STATE_CHANGED->value);
    expect($event->tournament_token)->toBe('b197b9e8-0d08-4227-aa17-ba38cb4c1731');
    expect($event->match_token)->toBeNull();
});

it('classifies tournament_sync from EventSyncData_t marker using EventToken field', function () {
    $event = ClassifyLogEvent::run(makeEventFromFixture('tournament_sync.txt'));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_SYNC->value);
    expect($event->tournament_token)->toBe('b197b9e8-0d08-4227-aa17-ba38cb4c1731');
});

it('classifies tournament_round_info from FlsTournamentRoundInfoMessage marker', function () {
    $event = ClassifyLogEvent::run(makeEventFromFixture('tournament_round_info.txt'));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_ROUND_INFO->value);
    expect($event->tournament_token)->toBe('44b6fb76-9171-4e21-a8b7-ff635ed06c8f');
});

it('classifies tournament_round_result from FlsTournamentRoundResultMessage marker', function () {
    $event = ClassifyLogEvent::run(makeEventFromFixture('tournament_round_result.txt'));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_ROUND_RESULT->value);
    expect($event->tournament_token)->toBe('32d98b9d-2af6-4cc7-a95d-cd7471d75809');
});

it('classifies tournament_player_eliminated from FlsTournamentPlayerIsEliminatedMessage marker', function () {
    $event = ClassifyLogEvent::run(makeEventFromFixture('tournament_player_eliminated.txt'));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_PLAYER_ELIMINATED->value);
    expect($event->tournament_token)->toBe('32d98b9d-2af6-4cc7-a95d-cd7471d75809');
});

it('classifies tournament_ended from FlsTournamentEndRespMessage marker', function () {
    $event = ClassifyLogEvent::run(makeEventFromFixture('tournament_ended.txt'));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_ENDED->value);
    expect($event->tournament_token)->toBe('87b97a55-bc56-47f9-866b-ca51ed0db0d5');
});

it('does not mis-route tournament_sync to game_management_json despite nested MatchToken', function () {
    $raw = '16:34:31 [INF] (Game Management|Processing Registered Handler for EventSyncData_t in TournamentUninitializedState) Processor: TournamentUninitializedState Message: {"EventToken":"b197b9e8-0d08-4227-aa17-ba38cb4c1731","MatchCreateInfo":{"MatchToken":"00000000-0000-0000-0000-000000000000","MatchID":0}} Receiver: WotC.MtGO.Client.Model.Play.TournamentEvent.Tournament';

    $event = (new LogEvent)->fill([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => strlen($raw),
        'timestamp' => '16:34:31',
        'level' => 'INF',
        'category' => 'Game Management',
        'context' => 'Processing Registered Handler for EventSyncData_t in TournamentUninitializedState',
        'raw_text' => $raw,
        'ingested_at' => now(),
        'logged_at' => now(),
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe(LogEventType::TOURNAMENT_SYNC->value);
    expect($result->tournament_token)->toBe('b197b9e8-0d08-4227-aa17-ba38cb4c1731');
});

it('still extracts tournament_token when outer JSON is malformed (missing closing brace)', function () {
    // Real MTGO occasionally ships FlsTournamentRoundInfoMessage with the outer
    // closing brace missing — the inner Round object is still balanced, so a
    // json_decode-based extractor would return the Round object (no Token key).
    // A regex-based token extractor survives this.
    $raw = '15:43:44 [INF] (Game Management|Processing Registered Handler for FlsTournamentRoundInfoMessage in TournamentNotJoinedFiredState) Processor: TournamentNotJoinedFiredState Message: {"Token":"1517df2c-e0ad-4bf3-8cd0-70cf3c200c7a","ID":12840476,"Round":{"Number":1,"Matches":[],"ByeList":[],"Results":[]} Receiver: WotC.MtGO.Client.Model.Play.TournamentEvent.Tournament';

    $event = (new LogEvent)->fill([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => strlen($raw),
        'timestamp' => '15:43:44',
        'level' => 'INF',
        'category' => 'Game Management',
        'context' => 'Processing Registered Handler for FlsTournamentRoundInfoMessage in TournamentNotJoinedFiredState',
        'raw_text' => $raw,
        'ingested_at' => now(),
        'logged_at' => now(),
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe(LogEventType::TOURNAMENT_ROUND_INFO->value);
    expect($result->tournament_token)->toBe('1517df2c-e0ad-4bf3-8cd0-70cf3c200c7a');
});
