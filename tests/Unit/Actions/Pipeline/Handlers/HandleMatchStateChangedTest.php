<?php

use App\Actions\Pipeline\Handlers\HandleMatchStateChanged;
use App\Enums\MatchState;
use App\Jobs\DetermineMatchArchetypesJob;
use App\Jobs\SubmitMatch;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('does nothing when match_token is missing', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'match_token' => null,
    ]);

    expect(fn () => (new HandleMatchStateChanged)->handle($event, new PipelineContext))
        ->not->toThrow(Throwable::class);

    expect(MtgoMatch::count())->toBe(0);
});

it('does nothing when match_id cannot be resolved', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'match_token' => 'orphan-token',
        'match_id' => null,
    ]);

    (new HandleMatchStateChanged)->handle($event, new PipelineContext);

    expect(MtgoMatch::count())->toBe(0);
});

it('remembers the match without dispatching closure jobs for non-terminal state', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->inProgress()->create([
        'mtgo_id' => 4242,
        'token' => 'in-progress-token',
    ]);

    // AdvanceMatchState requires a join-state event in the match_id event stream.
    LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'match_token' => 'in-progress-token',
        'match_id' => 4242,
        'context' => 'MatchJoinedEventUnderwayState',
    ]);

    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'match_token' => 'in-progress-token',
        'match_id' => 4242,
        'context' => 'MatchInProgressState',
    ]);

    $context = new PipelineContext;
    (new HandleMatchStateChanged)->handle($event, $context);

    expect($context->matchByToken('in-progress-token'))->not->toBeNull();
    expect($context->matchByToken('in-progress-token')->id)->toBe($match->id);

    Queue::assertNotPushed(DetermineMatchArchetypesJob::class);
    Queue::assertNotPushed(SubmitMatch::class);
});

it('chains into HandleMatchClosed when the match advances to a terminal state', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->ended()->create([
        'mtgo_id' => 9999,
        'token' => 'ended-token',
    ]);

    // AdvanceMatchState requires a join-state event in the match_id event stream
    // to pass the gate, even when the match is already terminal.
    LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'match_token' => 'ended-token',
        'match_id' => 9999,
        'context' => 'MatchJoinedEventUnderwayState',
    ]);

    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'match_token' => 'ended-token',
        'match_id' => 9999,
        'context' => 'MatchCompletedState',
    ]);

    $context = new PipelineContext;
    (new HandleMatchStateChanged)->handle($event, $context);

    expect($context->matchByToken('ended-token')->state)->toBe(MatchState::Ended);

    Queue::assertPushed(DetermineMatchArchetypesJob::class, function (DetermineMatchArchetypesJob $job) use ($match) {
        return $job->matchId === $match->id;
    });
    Queue::assertPushed(SubmitMatch::class);
});
