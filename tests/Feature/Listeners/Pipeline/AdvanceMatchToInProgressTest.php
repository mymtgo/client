<?php

use App\Enums\MatchState;
use App\Events\GameStateChanged;
use App\Listeners\Pipeline\AdvanceMatchToInProgress;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('advances a Started match to InProgress when game state events exist', function () {
    $match = MtgoMatch::factory()->create([
        'mtgo_id' => '12345',
        'token' => 'token-abc',
        'state' => MatchState::Started,
    ]);

    // The triggering game_state_update event
    $logEvent = LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_id' => '12345',
        'match_token' => 'token-abc',
        'game_id' => '1',
    ]);

    $listener = new AdvanceMatchToInProgress;
    $listener->handle(new GameStateChanged($logEvent));

    expect($match->fresh()->state)->toBe(MatchState::InProgress);
});

it('does nothing if match is not in Started state', function () {
    $match = MtgoMatch::factory()->create([
        'mtgo_id' => '12346',
        'token' => 'token-def',
        'state' => MatchState::InProgress,
    ]);

    $logEvent = LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_id' => '12346',
        'match_token' => 'token-def',
        'game_id' => '1',
    ]);

    $listener = new AdvanceMatchToInProgress;
    $listener->handle(new GameStateChanged($logEvent));

    expect($match->fresh()->state)->toBe(MatchState::InProgress);
});

it('does nothing if no match exists yet', function () {
    $logEvent = LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_id' => '99999',
        'match_token' => 'token-nonexistent',
        'game_id' => '1',
    ]);

    $listener = new AdvanceMatchToInProgress;
    $listener->handle(new GameStateChanged($logEvent));

    expect(MtgoMatch::where('mtgo_id', '99999')->exists())->toBeFalse();
});

it('backfills mtgo_id on the match if it was null', function () {
    $match = MtgoMatch::factory()->create([
        'mtgo_id' => null,
        'token' => 'token-ghi',
        'state' => MatchState::Started,
    ]);

    $logEvent = LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_id' => '77777',
        'match_token' => 'token-ghi',
        'game_id' => '1',
    ]);

    $listener = new AdvanceMatchToInProgress;
    $listener->handle(new GameStateChanged($logEvent));

    $fresh = $match->fresh();
    expect($fresh->mtgo_id)->toBe('77777');
    expect($fresh->state)->toBe(MatchState::InProgress);
});

it('does nothing if no game_state_update events exist in DB for the match', function () {
    $match = MtgoMatch::factory()->create([
        'mtgo_id' => '55555',
        'token' => 'token-jkl',
        'state' => MatchState::Started,
    ]);

    // Only the triggering event — no additional game_state_update rows in DB
    // (simulate: the event exists in memory but not yet persisted as a query result)
    // We use a non-game_state_update event type so the DB query returns empty
    $logEvent = LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_id' => '55555',
        'match_token' => 'token-jkl',
        'game_id' => null,
    ]);

    // Delete the event so DB has no game_state_update rows for this match
    $logEvent->delete();

    $listener = new AdvanceMatchToInProgress;
    $listener->handle(new GameStateChanged($logEvent));

    expect($match->fresh()->state)->toBe(MatchState::Started);
});
