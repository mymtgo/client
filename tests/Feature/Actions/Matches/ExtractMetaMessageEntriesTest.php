<?php

use App\Actions\Matches\ExtractMetaMessageEntries;
use App\Models\LogEvent;
use App\Models\LogInstance;
use Carbon\Carbon;

uses(\Illuminate\Foundation\Testing\LazilyRefreshDatabase::class);

function rawWithMeta(string $token, int $matchId, int $gameId, string $text, int $matchTime = 0): string
{
    $textBytes = array_map('ord', str_split($text));
    $len = strlen($text);
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [$len, 0, 0, 0],
        $textBytes
    );

    return sprintf(
        '%02d:00:00 [INF] (Game Management|Processing) Message: {"MatchToken":"%s","MatchID":%d,"GameID":%d,"MetaMessage":[%s]}',
        $matchTime,
        $token,
        $matchId,
        $gameId,
        implode(',', $bytes)
    );
}

it('returns empty array when no log_events match the token', function () {
    expect(ExtractMetaMessageEntries::run('nonexistent-token'))->toBe([]);
});

it('returns ordered entries for a single-game match', function () {
    $token = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    $instance = LogInstance::factory()->create();

    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'match_token' => $token,
        'match_id' => 1,
        'game_id' => 100,
        'event_type' => 'game_management_json',
        'timestamp' => Carbon::parse('2026-05-26 10:00:01'),
        'byte_offset_start' => 100,
        'raw_text' => rawWithMeta($token, 1, 100, '@PPlayerA rolled a 1.'),
    ]);
    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'match_token' => $token,
        'match_id' => 1,
        'game_id' => 100,
        'event_type' => 'game_management_json',
        'timestamp' => Carbon::parse('2026-05-26 10:00:02'),
        'byte_offset_start' => 200,
        'raw_text' => rawWithMeta($token, 1, 100, '@PPlayerB rolled a 6.'),
    ]);

    $entries = ExtractMetaMessageEntries::run($token);

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['message'])->toBe('@PPlayerA rolled a 1.')
        ->and($entries[1]['message'])->toBe('@PPlayerB rolled a 6.');
});

it('skips rows whose MetaMessage carries no recognised text', function () {
    $token = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    $instance = LogInstance::factory()->create();

    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'match_token' => $token,
        'match_id' => 2,
        'game_id' => 200,
        'event_type' => 'game_management_json',
        'timestamp' => Carbon::parse('2026-05-26 10:00:01'),
        'byte_offset_start' => 100,
        'raw_text' => '02:00:00 [INF] (Game Management|Processing) Message: {"MatchToken":"'.$token.'","MatchID":2,"GameID":200,"MetaMessage":[1,2,3,4,5,6,7,8]}',
    ]);
    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'match_token' => $token,
        'match_id' => 2,
        'game_id' => 200,
        'event_type' => 'game_management_json',
        'timestamp' => Carbon::parse('2026-05-26 10:00:02'),
        'byte_offset_start' => 200,
        'raw_text' => rawWithMeta($token, 2, 200, '@PPlayerA wins the game.'),
    ]);

    $entries = ExtractMetaMessageEntries::run($token);
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['message'])->toBe('@PPlayerA wins the game.');
});

it('orders entries by timestamp then byte_offset_start', function () {
    $token = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
    $instance = LogInstance::factory()->create();

    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'match_token' => $token,
        'event_type' => 'game_management_json',
        'timestamp' => Carbon::parse('2026-05-26 10:00:02'),
        'byte_offset_start' => 200,
        'raw_text' => rawWithMeta($token, 1, 1, '@PSecond rolled a 1.'),
    ]);
    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'match_token' => $token,
        'event_type' => 'game_management_json',
        'timestamp' => Carbon::parse('2026-05-26 10:00:01'),
        'byte_offset_start' => 100,
        'raw_text' => rawWithMeta($token, 1, 1, '@PFirst rolled a 2.'),
    ]);

    $entries = ExtractMetaMessageEntries::run($token);
    expect($entries[0]['message'])->toBe('@PFirst rolled a 2.')
        ->and($entries[1]['message'])->toBe('@PSecond rolled a 1.');
});

it('orders entries chronologically across log_instance boundaries', function () {
    $token = 'dddddddd-dddd-dddd-dddd-dddddddddddd';
    $oldInstance = LogInstance::factory()->create();
    $newInstance = LogInstance::factory()->create();

    LogEvent::factory()->create([
        'log_instance_id' => $oldInstance->id,
        'match_token' => $token,
        'event_type' => 'game_management_json',
        'timestamp' => Carbon::parse('2026-05-26 10:00:01'),
        'byte_offset_start' => 5000,
        'raw_text' => rawWithMeta($token, 1, 1, '@PAlice rolled a 4.'),
    ]);
    LogEvent::factory()->create([
        'log_instance_id' => $newInstance->id,
        'match_token' => $token,
        'event_type' => 'game_management_json',
        'timestamp' => Carbon::parse('2026-05-26 10:00:02'),
        'byte_offset_start' => 100,
        'raw_text' => rawWithMeta($token, 1, 1, '@PBob rolled a 3.'),
    ]);

    $entries = ExtractMetaMessageEntries::run($token);
    expect($entries[0]['message'])->toBe('@PAlice rolled a 4.')
        ->and($entries[1]['message'])->toBe('@PBob rolled a 3.');
});

it('ignores log_events with a different event_type', function () {
    $token = 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee';
    $instance = LogInstance::factory()->create();

    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'match_token' => $token,
        'event_type' => 'match_state_changed',
        'timestamp' => Carbon::parse('2026-05-26 10:00:01'),
        'byte_offset_start' => 100,
        'raw_text' => '02:00:00 [INF] (Game Management|Match State Changed for '.$token.' from X to Y)',
    ]);

    expect(ExtractMetaMessageEntries::run($token))->toBe([]);
});

it('returns timestamps in ISO8601 string format', function () {
    $token = 'ffffffff-ffff-ffff-ffff-ffffffffffff';
    $instance = LogInstance::factory()->create();

    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'match_token' => $token,
        'event_type' => 'game_management_json',
        'timestamp' => Carbon::parse('2026-05-26 10:00:01'),
        'byte_offset_start' => 100,
        'raw_text' => rawWithMeta($token, 1, 1, '@PPlayer rolled a 1.'),
    ]);

    $entries = ExtractMetaMessageEntries::run($token);
    expect($entries[0]['timestamp'])->toMatch('/^2026-05-26T\d{2}:00:01/');
});
