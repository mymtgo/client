<?php

use App\Actions\Pipeline\Handlers\HandleMatchClosed;
use App\Jobs\DetermineMatchArchetypesJob;
use App\Jobs\SubmitMatch;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('dispatches archetype detection + submission when the match is known', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->create(['token' => 'closing-token']);
    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'match_token' => 'closing-token',
    ]);

    $context = new PipelineContext;
    $context->rememberMatch($match);

    (new HandleMatchClosed)->handle($event, $context);

    Queue::assertPushed(DetermineMatchArchetypesJob::class, function (DetermineMatchArchetypesJob $job) use ($match) {
        return $job->matchId === $match->id;
    });

    Queue::assertPushed(SubmitMatch::class);
});

it('dispatches nothing when the token is unknown', function () {
    Queue::fake();

    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'match_token' => 'unknown-token',
    ]);

    (new HandleMatchClosed)->handle($event, new PipelineContext);

    Queue::assertNothingPushed();
});
