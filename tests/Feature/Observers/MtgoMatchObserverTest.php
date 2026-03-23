<?php

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Events\AppNotification;
use App\Jobs\ComputeCardGameStats;
use App\Jobs\SubmitMatch;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('dispatches enrichment jobs when match state changes to Complete', function () {
    Queue::fake();
    Event::fake([AppNotification::class]);

    $match = MtgoMatch::factory()->create(['state' => MatchState::Ended]);

    $match->update([
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
    ]);

    Queue::assertPushed(SubmitMatch::class);
    Queue::assertPushed(ComputeCardGameStats::class);
});

it('dispatches AppNotification when match completes', function () {
    Queue::fake();
    Event::fake([AppNotification::class]);

    $match = MtgoMatch::factory()->create(['state' => MatchState::Ended]);

    $match->update([
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
    ]);

    Event::assertDispatched(AppNotification::class, function (AppNotification $event) {
        return $event->type === 'match_win'
            && str_contains($event->title, 'Win');
    });
});

it('does not trigger enrichment for non-Complete state changes', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->create(['state' => MatchState::Started]);
    $match->update(['state' => MatchState::InProgress]);

    Queue::assertNotPushed(SubmitMatch::class);
    Queue::assertNotPushed(ComputeCardGameStats::class);
});

it('handles enrichment failures gracefully', function () {
    Queue::fake();
    Event::fake([AppNotification::class]);

    // DetermineMatchArchetypes::run() will run but exit early (no games).
    // The match should still be Complete regardless.
    $match = MtgoMatch::factory()->create(['state' => MatchState::Ended]);
    $match->update([
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Loss,
    ]);

    expect($match->fresh()->state)->toBe(MatchState::Complete);
});
