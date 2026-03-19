<?php

use App\Actions\Matches\BuildMatches;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('excludes league_joined events from match discovery', function () {
    LogEvent::create([
        'file_path' => '/test/log.txt',
        'byte_offset_start' => 1,
        'byte_offset_end' => 100,
        'timestamp' => now(),
        'level' => 'INF',
        'category' => 'UI',
        'context' => 'Creating GameDetailsView',
        'raw_text' => "12:24:23 [INF] (UI|Creating GameDetailsView) League\nEventToken=league-token\nEventId=10397",
        'event_type' => 'league_joined',
        'match_token' => 'league-token',
        'match_id' => '10397',
        'ingested_at' => now(),
        'logged_at' => now(),
    ]);

    BuildMatches::run();

    expect(MtgoMatch::count())->toBe(0);
});

it('marks stale events as processed when no join event exists after 2 minutes', function () {
    $event = LogEvent::create([
        'file_path' => '/test/log.txt',
        'byte_offset_start' => 1,
        'byte_offset_end' => 100,
        'timestamp' => now(),
        'level' => 'INF',
        'category' => 'Game Management',
        'context' => 'SomeContext',
        'raw_text' => 'Message: {"MatchToken":"stale-token","MatchID":99999}',
        'event_type' => 'game_management_json',
        'match_token' => 'stale-token',
        'match_id' => '99999',
        'username' => 'testuser',
        'ingested_at' => now()->subMinutes(3),
        'logged_at' => now()->subMinutes(3),
    ]);

    BuildMatches::run();

    $event->refresh();
    expect($event->processed_at)->not->toBeNull();
});

it('does not mark fresh events as processed when no join event exists', function () {
    $event = LogEvent::create([
        'file_path' => '/test/log.txt',
        'byte_offset_start' => 1,
        'byte_offset_end' => 100,
        'timestamp' => now(),
        'level' => 'INF',
        'category' => 'Game Management',
        'context' => 'SomeContext',
        'raw_text' => 'Message: {"MatchToken":"fresh-token","MatchID":88888}',
        'event_type' => 'game_management_json',
        'match_token' => 'fresh-token',
        'match_id' => '88888',
        'username' => 'testuser',
        'ingested_at' => now(),
        'logged_at' => now(),
    ]);

    BuildMatches::run();

    $event->refresh();
    expect($event->processed_at)->toBeNull();
});
