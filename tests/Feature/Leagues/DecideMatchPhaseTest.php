<?php

use App\Actions\Leagues\DecideMatchPhase;
use App\Enums\LogEventType;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function phaseMatch(): MtgoMatch
{
    return MtgoMatch::factory()->inProgress()->create(['mtgo_id' => '555001', 'started_at' => now()->subMinutes(20)]);
}

function logSideboardingState(MtgoMatch $match, Carbon\Carbon $at): void
{
    LogEvent::create([
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => 'mtgo.log', 'byte_offset_start' => 0, 'byte_offset_end' => 1,
        'timestamp' => $at->format('H:i:s'), 'logged_at' => $at->copy(),
        'level' => 'INF', 'category' => 'Game Management',
        'context' => 'Match State Changed from MatchJoinedGameStartedState to MatchJoinedSideboardingState',
        'raw_text' => 'Match State Changed', 'ingested_at' => now(),
        'match_token' => $match->token, 'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
    ]);
}

it('is sideboarding when the log shows the sideboarding state after a game ended', function () {
    $match = phaseMatch();
    Game::factory()->create(['match_id' => $match->id, 'started_at' => now()->subMinutes(18), 'ended_at' => now()->subMinutes(5)]);
    logSideboardingState($match, now()->subMinutes(4));

    expect(DecideMatchPhase::run($match))->toBe('sideboarding');
});

it('is in game when a game has ended_at but no sideboarding state was logged', function () {
    $match = phaseMatch();
    Game::factory()->create(['match_id' => $match->id, 'started_at' => now()->subMinutes(18), 'ended_at' => now()]);

    expect(DecideMatchPhase::run($match))->toBe('in_game');
});

it('is in game before any game has been logged', function () {
    expect(DecideMatchPhase::run(phaseMatch()))->toBe('in_game');
});
