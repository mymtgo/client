<?php

use App\Actions\Leagues\ProcessLeagueEvents;
use App\Enums\LeagueState;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\LogInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createLeagueJoinRequest(array $overrides = []): LogEvent
{
    return LogEvent::create(array_merge([
        'log_instance_id' => LogInstance::factory()->create()->id,
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

function createLeaguePanelView(int $eventId = 10397, string $eventToken = 'test-league-token', ?DateTimeInterface $loggedAt = null): LogEvent
{
    return LogEvent::create([
        'log_instance_id' => LogInstance::factory()->create()->id,
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
    ]);
}

function createLeagueDropEvent(?DateTimeInterface $loggedAt = null): LogEvent
{
    return LogEvent::create([
        'log_instance_id' => LogInstance::factory()->create()->id,
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

/*
|--------------------------------------------------------------------------
| Panel-view backfill — never creates a league
|--------------------------------------------------------------------------
*/

it('does not create a league from a panel-view event', function () {
    createLeaguePanelView();

    ProcessLeagueEvents::run();

    expect(League::count())->toBe(0);
});

it('marks panel-view events as processed even when no league exists', function () {
    $event = createLeaguePanelView();

    ProcessLeagueEvents::run();

    expect($event->fresh()->processed_at)->not->toBeNull();
});

it('backfills event_id and joined_at on an Active league with matching token', function () {
    $league = League::factory()->create([
        'token' => 'test-league-token',
        'event_id' => null,
        'joined_at' => null,
        'state' => LeagueState::Active,
    ]);

    $panelLoggedAt = now()->subSeconds(5);
    createLeaguePanelView(10397, 'test-league-token', $panelLoggedAt);

    ProcessLeagueEvents::run();

    $league->refresh();
    expect($league->event_id)->toBe(10397);
    expect($league->joined_at)->not->toBeNull();
});

it('does not overwrite an existing event_id', function () {
    $league = League::factory()->create([
        'token' => 'test-league-token',
        'event_id' => 99999,
        'state' => LeagueState::Active,
    ]);

    createLeaguePanelView(10397, 'test-league-token');

    ProcessLeagueEvents::run();

    expect($league->fresh()->event_id)->toBe(99999);
});

it('does not modify Partial or Complete leagues', function () {
    $partial = League::factory()->create([
        'token' => 'test-league-token',
        'event_id' => null,
        'state' => LeagueState::Partial,
    ]);

    createLeaguePanelView(10397, 'test-league-token');

    ProcessLeagueEvents::run();

    expect($partial->fresh()->event_id)->toBeNull();
    expect($partial->fresh()->state)->toBe(LeagueState::Partial);
});

it('marks join request events as processed', function () {
    $request = createLeagueJoinRequest();

    ProcessLeagueEvents::run();

    expect($request->fresh()->processed_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Drop attribution
|--------------------------------------------------------------------------
*/

it('marks the viewed league Dropped and stamps dropped_at on a league_dropped event', function () {
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

    createLeaguePanelView(22222, 'modern-token', now()->subSeconds(30));
    createLeaguePanelView(11111, 'pioneer-token', now()->subSeconds(5));
    createLeagueDropEvent();

    ProcessLeagueEvents::run();

    expect($pioneer->fresh()->state)->toBe(LeagueState::Dropped);
    expect($modern->fresh()->state)->toBe(LeagueState::Active);
});

it('falls back to token lookup when the panel-view event_id has not yet been backfilled', function () {
    // AssignLeague created a league before the panel-view backfill loop ran
    // (event_id still null on the league). Drop should still find it via
    // the panel-view's match_token.
    $league = League::factory()->create([
        'token' => 'test-league-token',
        'event_id' => null,
        'state' => LeagueState::Active,
    ]);

    // Mark the panel view processed so backfillFromPanelView is skipped,
    // simulating a panel view ingested in a prior tick.
    LogEvent::create([
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => '/test/log.txt',
        'byte_offset_start' => rand(1, 999999),
        'byte_offset_end' => rand(1, 999999),
        'timestamp' => now(),
        'level' => 'INF',
        'category' => 'UI',
        'context' => 'Creating GameDetailsView',
        'raw_text' => '',
        'event_type' => 'league_joined',
        'match_token' => 'test-league-token',
        'match_id' => '10397',
        'ingested_at' => now(),
        'logged_at' => now()->subSeconds(5),
        'processed_at' => now(),
    ]);

    createLeagueDropEvent();

    ProcessLeagueEvents::run();

    expect($league->fresh()->state)->toBe(LeagueState::Dropped);
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
    // event_id (caused by a duplicate-creation bug, since fixed). The drop
    // must hit the newer league, not the older orphan.
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
