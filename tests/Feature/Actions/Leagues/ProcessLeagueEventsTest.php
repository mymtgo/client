<?php

use App\Actions\Leagues\ProcessLeagueEvents;
use App\Enums\LeagueState;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createLeagueJoinEvent(array $overrides = []): LogEvent
{
    return LogEvent::create(array_merge([
        'file_path' => '/test/log.txt',
        'byte_offset_start' => rand(1, 999999),
        'byte_offset_end' => rand(1, 999999),
        'timestamp' => now(),
        'level' => 'INF',
        'category' => 'UI',
        'context' => 'Creating GameDetailsView',
        'raw_text' => "12:24:23 [INF] (UI|Creating GameDetailsView) League\nEventToken=test-league-token\nEventId=10397\nPlayFormatCd=Modern",
        'event_type' => 'league_joined',
        'match_token' => 'test-league-token',
        'match_id' => '10397',
        'ingested_at' => now(),
        'logged_at' => now(),
    ], $overrides));
}

it('creates a league from a join event', function () {
    createLeagueJoinEvent();

    ProcessLeagueEvents::run();

    $league = League::where('event_id', 10397)->first();
    expect($league)->not->toBeNull();
    expect($league->token)->toBe('test-league-token');
    expect($league->state)->toBe(LeagueState::Active);
    expect((bool) $league->phantom)->toBeFalse();
    expect($league->joined_at)->not->toBeNull();
});

it('marks existing active league as partial on re-join', function () {
    $oldLeague = League::factory()->create([
        'token' => 'test-league-token',
        'event_id' => 10397,
        'state' => LeagueState::Active,
    ]);

    MtgoMatch::factory()->create(['league_id' => $oldLeague->id]);

    createLeagueJoinEvent();
    ProcessLeagueEvents::run();

    $oldLeague->refresh();
    expect($oldLeague->state)->toBe(LeagueState::Partial);

    $newLeague = League::where('event_id', 10397)
        ->where('state', LeagueState::Active)
        ->first();
    expect($newLeague)->not->toBeNull();
    expect($newLeague->id)->not->toBe($oldLeague->id);
});

it('does not create duplicate league on repeated processing', function () {
    createLeagueJoinEvent();

    ProcessLeagueEvents::run();
    ProcessLeagueEvents::run();

    expect(League::where('event_id', 10397)->count())->toBe(1);
});

it('marks join event as processed', function () {
    $event = createLeagueJoinEvent();

    ProcessLeagueEvents::run();

    $event->refresh();
    expect($event->processed_at)->not->toBeNull();
});

it('does not mark empty active league as partial on re-join', function () {
    $emptyLeague = League::factory()->create([
        'token' => 'test-league-token',
        'event_id' => 10397,
        'state' => LeagueState::Active,
    ]);

    createLeagueJoinEvent();
    ProcessLeagueEvents::run();

    $emptyLeague->refresh();
    expect($emptyLeague->state)->toBe(LeagueState::Active);
    expect(League::where('event_id', 10397)->count())->toBe(1);
});

it('extracts format from raw_text', function () {
    createLeagueJoinEvent([
        'raw_text' => "12:24:23 [INF] (UI|Creating GameDetailsView) League\nEventToken=abc\nEventId=555\nPlayFormatCd=CPauper",
        'match_token' => 'abc',
        'match_id' => '555',
    ]);

    ProcessLeagueEvents::run();

    $league = League::where('event_id', 555)->first();
    expect($league->format)->toBe('CPauper');
});
