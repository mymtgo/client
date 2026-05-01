<?php

use App\Actions\Leagues\ProcessLeagueEvents;
use App\Enums\LeagueState;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createLeagueJoinRequest(array $overrides = []): LogEvent
{
    return LogEvent::create(array_merge([
        'file_path' => '/test/log.txt',
        'byte_offset_start' => rand(1, 999999),
        'byte_offset_end' => rand(1, 999999),
        'timestamp' => now(),
        'level' => 'INF',
        'category' => 'DEFAULT',
        'context' => '',
        'raw_text' => '12:24:23 [INF] (DEFAULT|) Send Class: FlsLeagueUserJoinReqMessage',
        'event_type' => 'league_join_request',
        'ingested_at' => now(),
        'logged_at' => now(),
    ], $overrides));
}

function createLeagueJoinEvent(array $overrides = []): LogEvent
{
    // Always create the preceding join request so ProcessLeagueEvents treats
    // this as a real join (not just a UI re-display).
    createLeagueJoinRequest(['logged_at' => $overrides['logged_at'] ?? now()]);

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

it('does not mark league as partial on UI re-display without join request', function () {
    $league = League::factory()->create([
        'token' => 'test-league-token',
        'event_id' => 10397,
        'state' => LeagueState::Active,
    ]);

    MtgoMatch::factory()->create(['league_id' => $league->id]);

    // Create a league_joined event WITHOUT a preceding join request
    LogEvent::create([
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
    ]);

    ProcessLeagueEvents::run();

    $league->refresh();
    expect($league->state)->toBe(LeagueState::Active);
    expect(League::where('token', 'test-league-token')->count())->toBe(1);
});

it('backfills event_id on reactive league from UI re-display', function () {
    $league = League::factory()->create([
        'token' => 'test-league-token',
        'event_id' => null,
        'state' => LeagueState::Active,
    ]);

    // league_joined event without join request — just a UI view
    LogEvent::create([
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
    ]);

    ProcessLeagueEvents::run();

    $league->refresh();
    expect($league->event_id)->toBe(10397);
});

it('ignores join request older than 10 seconds before league view', function () {
    // Join request 15 seconds before the league view — outside the window
    createLeagueJoinRequest(['logged_at' => now()->subSeconds(15)]);

    // league_joined event without its own join request (the helper would add one)
    LogEvent::create([
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
    ]);

    ProcessLeagueEvents::run();

    expect(League::where('event_id', 10397)->count())->toBe(0);
});

it('accepts join request within 10 seconds before league view', function () {
    // Join request 5 seconds before — inside the window
    createLeagueJoinRequest(['logged_at' => now()->subSeconds(5)]);

    LogEvent::create([
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
    ]);

    ProcessLeagueEvents::run();

    expect(League::where('event_id', 10397)->count())->toBe(1);
});

function createLeaguePanelView(int $eventId, string $eventToken, ?DateTimeInterface $loggedAt = null): LogEvent
{
    return LogEvent::create([
        'file_path' => '/test/log.txt',
        'byte_offset_start' => rand(1, 999999),
        'byte_offset_end' => rand(1, 999999),
        'timestamp' => now(),
        'level' => 'INF',
        'category' => 'UI',
        'context' => 'Creating GameDetailsView',
        'raw_text' => "12:24:23 [INF] (UI|Creating GameDetailsView) League\nEventToken={$eventToken}\nEventId={$eventId}\nPlayFormatCd=Modern",
        'event_type' => 'league_joined',
        'match_token' => $eventToken,
        'match_id' => (string) $eventId,
        'ingested_at' => now(),
        'logged_at' => $loggedAt ?? now(),
        'processed_at' => now(), // already-processed view from a prior tick
    ]);
}

function createLeagueDropEvent(?DateTimeInterface $loggedAt = null): LogEvent
{
    return LogEvent::create([
        'file_path' => '/test/log.txt',
        'byte_offset_start' => rand(1, 999999),
        'byte_offset_end' => rand(1, 999999),
        'timestamp' => now(),
        'level' => 'INF',
        'category' => 'DEFAULT',
        'context' => '',
        'raw_text' => '12:28:15 [INF] (DEFAULT|) Send Class: FlsLeagueUserDropReqMessage',
        'event_type' => 'league_dropped',
        'ingested_at' => now(),
        'logged_at' => $loggedAt ?? now(),
    ]);
}

it('marks the viewed league Partial and stamps dropped_at on a league_dropped event', function () {
    $league = League::factory()->create([
        'token' => 'test-league-token',
        'event_id' => 10397,
        'state' => LeagueState::Active,
    ]);

    createLeaguePanelView(10397, 'test-league-token', now()->subSeconds(5));
    createLeagueDropEvent();

    ProcessLeagueEvents::run();

    $league->refresh();
    expect($league->state)->toBe(LeagueState::Dropped);
    expect($league->dropped_at)->not->toBeNull();
});

it('attributes drop to the most recently viewed league when multiple are active', function () {
    // Pioneer was joined first, Modern joined later — so a naive
    // "latest active league" attribution would pick Modern. We verify that
    // attribution follows the panel view, not the started_at timestamp.
    $pioneer = League::factory()->create([
        'token' => 'pioneer-token',
        'event_id' => 11111,
        'state' => LeagueState::Active,
        'started_at' => now()->subHours(2),
    ]);

    $modern = League::factory()->create([
        'token' => 'modern-token',
        'event_id' => 22222,
        'state' => LeagueState::Active,
        'started_at' => now()->subMinutes(10),
    ]);

    // User views Modern panel, then Pioneer panel, then clicks Drop.
    createLeaguePanelView(22222, 'modern-token', now()->subSeconds(30));
    createLeaguePanelView(11111, 'pioneer-token', now()->subSeconds(5));
    createLeagueDropEvent();

    ProcessLeagueEvents::run();

    expect($pioneer->fresh()->state)->toBe(LeagueState::Dropped);
    expect($modern->fresh()->state)->toBe(LeagueState::Active);
});

it('marks league_dropped events as processed', function () {
    League::factory()->create([
        'token' => 'test-league-token',
        'event_id' => 10397,
        'state' => LeagueState::Active,
    ]);

    createLeaguePanelView(10397, 'test-league-token', now()->subSeconds(5));
    $event = createLeagueDropEvent();

    ProcessLeagueEvents::run();

    expect($event->fresh()->processed_at)->not->toBeNull();
});

it('does nothing on a league_dropped event when no panel view precedes it', function () {
    $league = League::factory()->create([
        'token' => 'test-league-token',
        'event_id' => 10397,
        'state' => LeagueState::Active,
    ]);

    createLeagueDropEvent();

    ProcessLeagueEvents::run();

    expect($league->fresh()->state)->toBe(LeagueState::Active);
});

it('attributes drop to the most recently started Active league when two share an event_id', function () {
    // Defense-in-depth: legacy data had two Active leagues with the same
    // event_id (caused by AssignLeague duplicate-creation bug, since fixed).
    // The drop must hit the newer league, not the older orphan.
    $orphan = League::factory()->create([
        'token' => 'test-league-token',
        'event_id' => 10397,
        'state' => LeagueState::Active,
        'started_at' => now()->subHour(),
    ]);

    $real = League::factory()->create([
        'token' => 'test-league-token',
        'event_id' => 10397,
        'state' => LeagueState::Active,
        'started_at' => now()->subMinutes(10),
    ]);

    createLeaguePanelView(10397, 'test-league-token', now()->subSeconds(5));
    createLeagueDropEvent();

    ProcessLeagueEvents::run();

    expect($real->fresh()->state)->toBe(LeagueState::Dropped);
    expect($orphan->fresh()->state)->toBe(LeagueState::Active);
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
