<?php

use App\Actions\Pipeline\DispatchDomainEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
use App\Events\CardRevealed;
use App\Events\DeckUsedInGame;
use App\Events\GameResultDetermined;
use App\Events\GameStateChanged;
use App\Events\LeagueJoined;
use App\Events\LeagueJoinRequested;
use App\Events\MatchEnded;
use App\Events\MatchJoined;
use App\Events\MatchMetadataReceived;
use App\Events\UserLoggedIn;
use App\Models\LogEvent;
use Illuminate\Support\Facades\Event;

it('dispatches MatchJoined for match_state_changed join events', function () {
    Event::fake();
    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'match_token' => 'token-1',
    ]);

    DispatchDomainEvents::run(collect([$event]));

    Event::assertDispatched(MatchJoined::class, fn ($e) => $e->logEvent->id === $event->id);
});

it('dispatches MatchEnded for match_state_changed end events', function () {
    Event::fake();
    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'context' => 'TournamentMatchClosedState',
        'match_token' => 'token-1',
    ]);

    DispatchDomainEvents::run(collect([$event]));

    Event::assertDispatched(MatchEnded::class);
});

it('dispatches MatchEnded for concede pattern', function () {
    Event::fake();
    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'context' => 'ConcedeReqState to SomeNotJoinedState',
        'match_token' => 'token-1',
    ]);

    DispatchDomainEvents::run(collect([$event]));

    Event::assertDispatched(MatchEnded::class);
});

it('dispatches GameStateChanged for game_state_update events', function () {
    Event::fake();
    $event = LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_token' => 'token-1',
        'game_id' => '99',
    ]);

    DispatchDomainEvents::run(collect([$event]));

    Event::assertDispatched(GameStateChanged::class);
});

it('dispatches GameResultDetermined for game_result events', function () {
    Event::fake();
    $event = LogEvent::factory()->create([
        'event_type' => 'game_result',
        'match_token' => 'token-1',
        'game_id' => '99',
    ]);

    DispatchDomainEvents::run(collect([$event]));

    Event::assertDispatched(GameResultDetermined::class);
});

it('dispatches DeckUsedInGame for deck_used events', function () {
    Event::fake();
    $event = LogEvent::factory()->create(['event_type' => 'deck_used']);

    DispatchDomainEvents::run(collect([$event]));

    Event::assertDispatched(DeckUsedInGame::class);
});

it('dispatches CardRevealed for card_revealed events', function () {
    Event::fake();
    $event = LogEvent::factory()->create(['event_type' => 'card_revealed']);

    DispatchDomainEvents::run(collect([$event]));

    Event::assertDispatched(CardRevealed::class);
});

it('dispatches LeagueJoined for league_joined events', function () {
    Event::fake();
    $event = LogEvent::factory()->create(['event_type' => 'league_joined']);

    DispatchDomainEvents::run(collect([$event]));

    Event::assertDispatched(LeagueJoined::class);
});

it('dispatches LeagueJoinRequested for league_join_request events', function () {
    Event::fake();
    $event = LogEvent::factory()->create(['event_type' => 'league_join_request']);

    DispatchDomainEvents::run(collect([$event]));

    Event::assertDispatched(LeagueJoinRequested::class);
});

it('dispatches MatchMetadataReceived for game_management_json events', function () {
    Event::fake();
    $event = LogEvent::factory()->create(['event_type' => 'game_management_json']);

    DispatchDomainEvents::run(collect([$event]));

    Event::assertDispatched(MatchMetadataReceived::class);
});

it('dispatches multiple events for a batch of LogEvents', function () {
    Event::fake();
    $events = collect([
        LogEvent::factory()->create(['event_type' => 'match_state_changed', 'context' => 'MatchJoinedEventUnderwayState', 'match_token' => 'tok']),
        LogEvent::factory()->create(['event_type' => 'game_state_update', 'match_token' => 'tok', 'game_id' => '1']),
        LogEvent::factory()->create(['event_type' => 'game_state_update', 'match_token' => 'tok', 'game_id' => '2']),
    ]);

    DispatchDomainEvents::run($events);

    Event::assertDispatched(MatchJoined::class, 1);
    Event::assertDispatched(GameStateChanged::class, 2);
});

it('ignores LogEvents with unknown event types', function () {
    $event = LogEvent::factory()->create(['event_type' => 'some_unknown_type']);

    Event::fake();

    DispatchDomainEvents::run(collect([$event]));

    Event::assertNothingDispatched();
});

it('dispatches UserLoggedIn for login events with null event_type', function () {
    Event::fake();
    $event = LogEvent::factory()->create([
        'event_type' => null,
        'category' => 'Login',
        'context' => 'MtGO Login Success',
        'raw_text' => 'Username: TestUser',
    ]);

    DispatchDomainEvents::run(collect([$event]));

    Event::assertDispatched(UserLoggedIn::class);
});

it('dispatches MatchEnded for all end signal types', function (string $signal) {
    Event::fake();
    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'context' => $signal,
        'match_token' => 'tok',
    ]);

    DispatchDomainEvents::run(collect([$event]));

    Event::assertDispatched(MatchEnded::class);
})->with([
    'TournamentMatchClosedState',
    'MatchCompletedState',
    'MatchEndedState',
    'MatchClosedState',
    'JoinedCompletedState',
]);
