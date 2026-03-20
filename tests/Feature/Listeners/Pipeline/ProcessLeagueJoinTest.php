<?php

use App\Events\LeagueJoined;
use App\Events\LeagueJoinRequested;
use App\Listeners\Pipeline\ProcessLeagueJoin;
use App\Models\League;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a league when LeagueJoined event fires with a matching join request', function () {
    $token = 'league-token-abc';

    // Create a join request event first (within 10 seconds)
    LogEvent::factory()->create([
        'event_type' => 'league_join_request',
        'logged_at' => now(),
        'processed_at' => null,
    ]);

    // Create the league_joined event
    $joinEvent = LogEvent::factory()->create([
        'event_type' => 'league_joined',
        'match_token' => $token,
        'match_id' => '12345',
        'raw_text' => 'PlayFormatCd=Pmodern SomeOtherText',
        'logged_at' => now(),
        'processed_at' => null,
    ]);

    $listener = new ProcessLeagueJoin;
    $listener->handle(new LeagueJoined($joinEvent));

    expect(League::where('token', $token)->exists())->toBeTrue();
    $joinEvent->refresh();
    expect($joinEvent->processed_at)->not->toBeNull();
});

it('handles LeagueJoinRequested event without errors (smoke test)', function () {
    $requestEvent = LogEvent::factory()->create([
        'event_type' => 'league_join_request',
        'match_token' => 'some-token',
        'logged_at' => now(),
        'processed_at' => null,
    ]);

    $listener = new ProcessLeagueJoin;

    // Should not throw
    $listener->handle(new LeagueJoinRequested($requestEvent));

    expect(true)->toBeTrue();
});
