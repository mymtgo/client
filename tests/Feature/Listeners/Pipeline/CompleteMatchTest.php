<?php

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Events\MatchEnded;
use App\Jobs\ComputeCardGameStats;
use App\Jobs\SubmitMatch;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Without a GameLog .dat file, GetGameLog::run() returns null.
 * DetermineMatchResult then falls back to state-change analysis:
 * no concede detected = assumed win (2-0 for BO3).
 */
it('transitions an Ended match to Complete with results', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->create([
        'token' => 'token-complete',
        'state' => MatchState::Ended,
        'outcome' => MatchOutcome::Unknown,
    ]);

    // Create join event for metadata extraction
    LogEvent::factory()->create([
        'match_token' => 'token-complete',
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => "Receiver: match\nPlayFormatCd=Modern\nGameStructureCd=Constructed",
    ]);

    // Trigger event
    $triggerEvent = LogEvent::factory()->create([
        'match_token' => 'token-complete',
        'event_type' => 'match_state_changed',
        'context' => 'TournamentMatchClosedState',
    ]);

    $listener = new \App\Listeners\Pipeline\CompleteMatch;
    $listener->handle(new MatchEnded($triggerEvent));

    $match->refresh();
    expect($match->state)->toBe(MatchState::Complete);
    // No game log + no concede = assumed win
    expect($match->outcome)->toBe(MatchOutcome::Win);
});

it('detects a loss when local player conceded', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->create([
        'token' => 'token-concede',
        'state' => MatchState::Ended,
        'outcome' => MatchOutcome::Unknown,
    ]);

    LogEvent::factory()->create([
        'match_token' => 'token-concede',
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => "Receiver: match\nPlayFormatCd=Modern\nGameStructureCd=Constructed",
    ]);

    // Concede state change
    LogEvent::factory()->create([
        'match_token' => 'token-concede',
        'event_type' => 'match_state_changed',
        'context' => 'MatchConcedeReqState to MatchNotJoinedEventUnderwayState',
    ]);

    $triggerEvent = LogEvent::factory()->create([
        'match_token' => 'token-concede',
        'event_type' => 'match_state_changed',
        'context' => 'TournamentMatchClosedState',
    ]);

    $listener = new \App\Listeners\Pipeline\CompleteMatch;
    $listener->handle(new MatchEnded($triggerEvent));

    $match->refresh();
    expect($match->state)->toBe(MatchState::Complete);
    expect($match->outcome)->toBe(MatchOutcome::Loss);
});

it('does nothing if match is not in Ended state', function () {
    foreach ([MatchState::Started, MatchState::InProgress, MatchState::Complete] as $state) {
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

        $listener = new \App\Listeners\Pipeline\CompleteMatch;
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

    $listener = new \App\Listeners\Pipeline\CompleteMatch;
    $listener->handle(new MatchEnded($logEvent));

    expect(MtgoMatch::where('token', 'nonexistent-token')->exists())->toBeFalse();
});

it('marks log events as processed', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->create([
        'token' => 'token-processed',
        'mtgo_id' => '12345',
        'state' => MatchState::Ended,
        'outcome' => MatchOutcome::Unknown,
    ]);

    $joinEvent = LogEvent::factory()->create([
        'match_token' => 'token-processed',
        'match_id' => '12345',
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => "Receiver: match\nPlayFormatCd=Modern\nGameStructureCd=Constructed",
        'processed_at' => null,
    ]);

    $endEvent = LogEvent::factory()->create([
        'match_token' => 'token-processed',
        'event_type' => 'match_state_changed',
        'context' => 'TournamentMatchClosedState',
        'processed_at' => null,
    ]);

    $listener = new \App\Listeners\Pipeline\CompleteMatch;
    $listener->handle(new MatchEnded($endEvent));

    $joinEvent->refresh();
    $endEvent->refresh();

    expect($joinEvent->processed_at)->not->toBeNull();
    expect($endEvent->processed_at)->not->toBeNull();
});

it('clears archetype detection cache on match completion', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->create([
        'token' => 'some-token',
        'state' => MatchState::Ended,
        'outcome' => MatchOutcome::Unknown,
    ]);

    LogEvent::factory()->create([
        'match_token' => 'some-token',
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => "Receiver: match\nPlayFormatCd=Modern\nGameStructureCd=Constructed",
    ]);

    Cache::put('archetype_detect:some-token:cards', [['card_name' => 'Bolt', 'quantity' => 1, 'player' => 'Opp']]);
    Cache::put('archetype_detect:some-token:version', 5);

    $triggerEvent = LogEvent::factory()->create([
        'match_token' => 'some-token',
        'event_type' => 'match_state_changed',
        'context' => 'TournamentMatchClosedState',
    ]);

    $listener = new \App\Listeners\Pipeline\CompleteMatch;
    $listener->handle(new MatchEnded($triggerEvent));

    expect(Cache::get('archetype_detect:some-token:cards'))->toBeNull();
    expect(Cache::get('archetype_detect:some-token:version'))->toBeNull();
});

it('dispatches SubmitMatch and ComputeCardGameStats jobs', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->create([
        'token' => 'token-jobs',
        'state' => MatchState::Ended,
        'outcome' => MatchOutcome::Unknown,
    ]);

    LogEvent::factory()->create([
        'match_token' => 'token-jobs',
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => "Receiver: match\nPlayFormatCd=Modern\nGameStructureCd=Constructed",
    ]);

    $triggerEvent = LogEvent::factory()->create([
        'match_token' => 'token-jobs',
        'event_type' => 'match_state_changed',
        'context' => 'TournamentMatchClosedState',
    ]);

    $listener = new \App\Listeners\Pipeline\CompleteMatch;
    $listener->handle(new MatchEnded($triggerEvent));

    Queue::assertPushed(SubmitMatch::class);
    Queue::assertPushed(ComputeCardGameStats::class);
});
