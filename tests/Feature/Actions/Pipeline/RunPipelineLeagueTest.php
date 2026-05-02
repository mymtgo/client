<?php

use App\Actions\Pipeline\RunPipeline;
use App\Enums\LeagueState;
use App\Managers\MtgoManager;
use App\Models\League;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $mock = Mockery::mock(MtgoManager::class)->makePartial();
    $mock->shouldReceive('pathsAreValid')->andReturn(true);
    $mock->shouldReceive('ingestLogs')->andReturnNull();
    $mock->shouldReceive('getLogDataPath')->andReturn(sys_get_temp_dir());
    app()->instance('mtgo', $mock);
});

it('backfills event_id on an Active league when a panel-view event is processed', function () {
    $league = League::factory()->create([
        'token' => 'test-token',
        'event_id' => null,
        'state' => LeagueState::Active,
    ]);

    LogEvent::create([
        'file_path' => '/test/log',
        'byte_offset_start' => 1,
        'byte_offset_end' => 2,
        'timestamp' => now(),
        'level' => 'INF',
        'category' => 'DEFAULT',
        'context' => '',
        'raw_text' => '12:24:23 [INF] (DEFAULT|) Send Class: FlsLeagueUserJoinReqMessage',
        'event_type' => 'league_join_request',
        'logged_at' => now(),
        'ingested_at' => now(),
    ]);

    LogEvent::create([
        'file_path' => '/test/log',
        'byte_offset_start' => 3,
        'byte_offset_end' => 4,
        'timestamp' => now(),
        'level' => 'INF',
        'category' => 'UI',
        'context' => 'Creating GameDetailsView',
        'raw_text' => "12:24:23 [INF] (UI|Creating GameDetailsView) League\nEventToken=test-token\nEventId=99999\nPlayFormatCd=Modern",
        'event_type' => 'league_joined',
        'match_token' => 'test-token',
        'match_id' => '99999',
        'logged_at' => now(),
        'ingested_at' => now(),
    ]);

    RunPipeline::run();

    expect($league->fresh()->event_id)->toBe(99999);
    expect($league->fresh()->state)->toBe(LeagueState::Active);
});

it('marks league_joined events as processed after a pipeline tick', function () {
    LogEvent::create([
        'file_path' => '/test/log',
        'byte_offset_start' => 1,
        'byte_offset_end' => 2,
        'timestamp' => now(),
        'level' => 'INF',
        'category' => 'DEFAULT',
        'context' => '',
        'raw_text' => '12:24:23 [INF] (DEFAULT|) Send Class: FlsLeagueUserJoinReqMessage',
        'event_type' => 'league_join_request',
        'logged_at' => now(),
        'ingested_at' => now(),
    ]);

    $joinEvent = LogEvent::create([
        'file_path' => '/test/log',
        'byte_offset_start' => 3,
        'byte_offset_end' => 4,
        'timestamp' => now(),
        'level' => 'INF',
        'category' => 'UI',
        'context' => 'Creating GameDetailsView',
        'raw_text' => "12:24:23 [INF] (UI|Creating GameDetailsView) League\nEventToken=test-token\nEventId=99999\nPlayFormatCd=Modern",
        'event_type' => 'league_joined',
        'match_token' => 'test-token',
        'match_id' => '99999',
        'logged_at' => now(),
        'ingested_at' => now(),
    ]);

    RunPipeline::run();

    expect($joinEvent->fresh()->processed_at)->not->toBeNull();
});
