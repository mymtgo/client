<?php

use App\Actions\Matches\AbandonStaleMatches;
use App\Enums\MatchState;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function stuckMatch(string $token, string $mtgoId, MatchState $state = MatchState::InProgress): MtgoMatch
{
    return MtgoMatch::create([
        'mtgo_id' => $mtgoId,
        'token' => $token,
        'format' => 'Modern',
        'match_type' => 'Swiss',
        'started_at' => now()->subHours(3),
        'state' => $state,
    ]);
}

function stateChangeEvent(string $token, string $mtgoId, string $context, Carbon $loggedAt, ?Carbon $processedAt = null): void
{
    LogEvent::create([
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => '/tmp/abandon.log',
        'byte_offset_start' => 1,
        'byte_offset_end' => 2,
        'timestamp' => $loggedAt->format('H:i:s'),
        'level' => 'Info',
        'category' => 'Match',
        'context' => $context,
        'raw_text' => '(Game Management|'.$context.')',
        'ingested_at' => $loggedAt,
        'logged_at' => $loggedAt,
        'processed_at' => $processedAt,
        'match_token' => $token,
        'match_id' => $mtgoId,
        'event_type' => 'match_state_changed',
    ]);
}

it('marks a stale in_progress match with no end signal as abandoned', function () {
    $match = stuckMatch('tok-a', '1');
    stateChangeEvent('tok-a', '1', 'Match State Changed for tok-a from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState', now()->subMinutes(90), processedAt: now());

    AbandonStaleMatches::run();

    $match->refresh();
    expect($match->state)->toBe(MatchState::Abandoned);
    expect($match->ended_at)->not->toBeNull();
});

it('leaves an in_progress match with recent activity alone', function () {
    $match = stuckMatch('tok-b', '2');
    stateChangeEvent('tok-b', '2', 'Match State Changed for tok-b from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState', now()->subMinutes(5), processedAt: now());

    AbandonStaleMatches::run();

    expect($match->refresh()->state)->toBe(MatchState::InProgress);
});

it('does not abandon a stale match that already carries an end signal', function () {
    $match = stuckMatch('tok-c', '3');
    stateChangeEvent('tok-c', '3', 'Match State Changed for tok-c from MatchJoinedCompletedState to MatchClosedState', now()->subMinutes(90));

    AbandonStaleMatches::run();

    // Resolvable by reprocessing (ProcessMatchEvents), so the reaper must not touch it.
    expect($match->refresh()->state)->toBe(MatchState::InProgress);
});

it('ignores matches that are not in_progress', function () {
    $match = stuckMatch('tok-d', '4', MatchState::Complete);
    stateChangeEvent('tok-d', '4', 'Match State Changed for tok-d from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState', now()->subMinutes(90), processedAt: now());

    AbandonStaleMatches::run();

    expect($match->refresh()->state)->toBe(MatchState::Complete);
});

it('marks the abandoned match unprocessed events as processed to stop rediscovery', function () {
    $match = stuckMatch('tok-e', '5');
    stateChangeEvent('tok-e', '5', 'Match State Changed for tok-e from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState', now()->subMinutes(90), processedAt: null);

    AbandonStaleMatches::run();

    expect($match->refresh()->state)->toBe(MatchState::Abandoned);
    expect(LogEvent::where('match_token', 'tok-e')->whereNull('processed_at')->count())->toBe(0);
});
