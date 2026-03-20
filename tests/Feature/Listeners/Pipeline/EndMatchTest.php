<?php

use App\Enums\MatchState;
use App\Events\MatchEnded;
use App\Listeners\Pipeline\CompleteMatch;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('transitions an InProgress match to Ended (and then Complete)', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->create([
        'token' => 'token-end',
        'state' => MatchState::InProgress,
        'ended_at' => now(),
    ]);

    // Create join event for metadata extraction (needed by CompleteMatch)
    LogEvent::factory()->create([
        'match_token' => 'token-end',
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => "Receiver: match\nPlayFormatCd=Modern\nGameStructureCd=Constructed",
        'timestamp' => '15:00:00',
        'logged_at' => '2026-03-20',
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-end',
        'event_type' => 'match_state_changed',
        'context' => 'TournamentMatchClosedState',
        'timestamp' => '15:30:00',
        'logged_at' => '2026-03-20',
    ]);

    $listener = new CompleteMatch;
    $listener->handle(new MatchEnded($logEvent));

    $match->refresh();
    // The merged listener transitions InProgress → Ended → Complete in one pass
    expect($match->state)->toBe(MatchState::Complete);
});

it('does nothing if match is in Started state', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-started',
        'state' => MatchState::Started,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-started',
        'event_type' => 'match_state_changed',
        'context' => 'TournamentMatchClosedState',
    ]);

    $listener = new CompleteMatch;
    $listener->handle(new MatchEnded($logEvent));

    $match = MtgoMatch::where('token', 'token-started')->first();
    expect($match->state)->toBe(MatchState::Started);
});

it('does nothing if match does not exist', function () {
    $logEvent = LogEvent::factory()->create([
        'match_token' => 'nonexistent-token',
        'event_type' => 'match_state_changed',
        'context' => 'TournamentMatchClosedState',
    ]);

    $listener = new CompleteMatch;
    $listener->handle(new MatchEnded($logEvent));

    expect(MtgoMatch::where('token', 'nonexistent-token')->exists())->toBeFalse();
});

it('sets ended_at timestamp from the last log event during InProgress → Ended transition', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->create([
        'token' => 'token-timestamp',
        'state' => MatchState::InProgress,
        'ended_at' => now()->subHour(),
    ]);

    // Create join event for metadata extraction
    LogEvent::factory()->create([
        'match_token' => 'token-timestamp',
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => "Receiver: match\nPlayFormatCd=Modern\nGameStructureCd=Constructed",
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

    $listener = new CompleteMatch;
    $listener->handle(new MatchEnded($triggerEvent));

    $match->refresh();
    expect($match->ended_at->format('H:i:s'))->toBe('15:45:00');
    expect($match->ended_at->format('Y-m-d'))->toBe('2026-03-20');
});
