<?php

use App\Actions\Logs\ClassifyLogEvent;
use App\Actions\Tournaments\ExtractTournamentPayload;
use App\Enums\LogEventType;
use App\Models\LogEvent;

function makeClassifiedEvent(string $filename): LogEvent
{
    $path = base_path("tests/Fixtures/log_samples/{$filename}");
    $raw = trim(file_get_contents($path));

    preg_match(
        '/^(?<time>\d{2}:\d{2}:\d{2}) \[(?<level>\w+)\] \((?<cat>[^|]+)\|(?<ctx>[^\)]*)\)/',
        $raw,
        $m,
    );

    $event = (new LogEvent)->fill([
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

    return ClassifyLogEvent::run($event);
}

it('extracts from/to state names for tournament_state_changed', function () {
    $payload = ExtractTournamentPayload::run(makeClassifiedEvent('tournament_state_changed.txt'));

    expect($payload)->toMatchArray([
        'from' => 'TournamentUninitializedState',
        'to' => 'PremierNotJoinedAwaitingMinPlayersState',
    ]);
});

it('extracts JSON body for tournament_sync', function () {
    $payload = ExtractTournamentPayload::run(makeClassifiedEvent('tournament_sync.txt'));

    expect($payload)->toHaveKey('EventToken', 'b197b9e8-0d08-4227-aa17-ba38cb4c1731');
    expect($payload)->toHaveKey('EventID', 12839726);
});

it('extracts JSON body for tournament_round_info', function () {
    $payload = ExtractTournamentPayload::run(makeClassifiedEvent('tournament_round_info.txt'));

    expect($payload)->toHaveKey('Token', '44b6fb76-9171-4e21-a8b7-ff635ed06c8f');
    expect($payload['Round'])->toBeArray();
});

it('extracts JSON body for tournament_round_result', function () {
    $payload = ExtractTournamentPayload::run(makeClassifiedEvent('tournament_round_result.txt'));

    expect($payload)->toHaveKey('Token', '32d98b9d-2af6-4cc7-a95d-cd7471d75809');
    expect($payload)->toHaveKey('Round', 2);
});

it('extracts JSON body for tournament_player_eliminated', function () {
    $payload = ExtractTournamentPayload::run(makeClassifiedEvent('tournament_player_eliminated.txt'));

    expect($payload)->toHaveKey('Token', '32d98b9d-2af6-4cc7-a95d-cd7471d75809');
    expect($payload)->toHaveKey('LoginID', 2320156);
});

it('extracts JSON body for tournament_ended', function () {
    $payload = ExtractTournamentPayload::run(makeClassifiedEvent('tournament_ended.txt'));

    expect($payload)->toHaveKey('Token', '87b97a55-bc56-47f9-866b-ca51ed0db0d5');
    expect($payload)->toHaveKey('ReturnCode', 0);
});

it('returns empty array for unclassified events', function () {
    $event = (new LogEvent)->fill([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => 10,
        'timestamp' => '00:00:00',
        'level' => 'INF',
        'category' => '',
        'context' => '',
        'raw_text' => 'nothing',
        'ingested_at' => now(),
        'logged_at' => now(),
        'event_type' => null,
    ]);

    expect(ExtractTournamentPayload::run($event))->toBe([]);
});
