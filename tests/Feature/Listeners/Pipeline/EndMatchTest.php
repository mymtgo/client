<?php

use App\Enums\MatchState;
use App\Events\MatchEnded;
use App\Listeners\Pipeline\EndMatch;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('transitions an InProgress match to Ended', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-end',
        'state' => MatchState::InProgress,
        'ended_at' => now(),
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-end',
        'event_type' => 'match_state_changed',
        'context' => 'TournamentMatchClosedState',
        'timestamp' => '15:30:00',
        'logged_at' => '2026-03-20',
    ]);

    $listener = new EndMatch;
    $listener->handle(new MatchEnded($logEvent));

    $match->refresh();
    expect($match->state)->toBe(MatchState::Ended);
});

it('does nothing if match is not InProgress', function () {
    foreach ([MatchState::Started, MatchState::Ended, MatchState::Complete] as $state) {
        $token = 'token-'.$state->value;

        MtgoMatch::factory()->create([
            'token' => $token,
            'state' => $state,
        ]);

        $logEvent = LogEvent::factory()->create([
            'match_token' => $token,
            'event_type' => 'match_state_changed',
            'context' => 'TournamentMatchClosedState',
        ]);

        $listener = new EndMatch;
        $listener->handle(new MatchEnded($logEvent));

        $match = MtgoMatch::where('token', $token)->first();
        expect($match->state)->toBe($state);
    }
});

it('does nothing if match does not exist', function () {
    $logEvent = LogEvent::factory()->create([
        'match_token' => 'nonexistent-token',
        'event_type' => 'match_state_changed',
        'context' => 'TournamentMatchClosedState',
    ]);

    $listener = new EndMatch;
    $listener->handle(new MatchEnded($logEvent));

    expect(MtgoMatch::where('token', 'nonexistent-token')->exists())->toBeFalse();
});

it('sets ended_at timestamp from the last log event', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-timestamp',
        'state' => MatchState::InProgress,
        'ended_at' => now()->subHour(),
    ]);

    // Create an earlier event
    LogEvent::factory()->create([
        'match_token' => 'token-timestamp',
        'event_type' => 'match_state_changed',
        'context' => 'SomeEarlierState',
        'timestamp' => '14:00:00',
        'logged_at' => '2026-03-20',
    ]);

    // Create a later event (the one that triggers the listener)
    $triggerEvent = LogEvent::factory()->create([
        'match_token' => 'token-timestamp',
        'event_type' => 'match_state_changed',
        'context' => 'TournamentMatchClosedState',
        'timestamp' => '15:45:00',
        'logged_at' => '2026-03-20',
    ]);

    $listener = new EndMatch;
    $listener->handle(new MatchEnded($triggerEvent));

    $match->refresh();
    expect($match->ended_at->format('H:i:s'))->toBe('15:45:00');
    expect($match->ended_at->format('Y-m-d'))->toBe('2026-03-20');
});
