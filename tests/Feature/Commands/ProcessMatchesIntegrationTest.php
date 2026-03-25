<?php

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('game log check does not crash for InProgress match without game log', function () {
    // InProgress match with no GameLog record — should gracefully skip
    $match = MtgoMatch::factory()->inProgress()->create(['token' => 'no-gamelog']);

    mockMtgoManager();

    $this->artisan('mtgo:process-matches')->assertSuccessful();

    // Match stays InProgress, no crash
    expect($match->fresh()->state)->toBe(MatchState::InProgress);
    expect($match->fresh()->attempts)->toBe(0);
});

it('is idempotent for completed matches — running twice produces same result', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
    ]);

    mockMtgoManager();

    $this->artisan('mtgo:process-matches')->assertSuccessful();
    $this->artisan('mtgo:process-matches')->assertSuccessful();

    expect($match->fresh()->state)->toBe(MatchState::Complete);
    expect($match->fresh()->outcome)->toBe(MatchOutcome::Win);
});

it('second loop skips matches already processed in first loop', function () {
    // Create a match with unprocessed events AND in InProgress state
    // It should be processed in the first loop only
    $match = MtgoMatch::factory()->inProgress()->create([
        'token' => 'dual-loop-test',
        'mtgo_id' => '55555',
    ]);

    createPipelineLogEvent([
        'match_id' => '55555',
        'match_token' => 'dual-loop-test',
        'event_type' => 'game_state_update',
        'context' => 'MatchJoinedEventUnderwayState',
        'username' => 'testplayer',
    ]);

    mockMtgoManager();

    $this->artisan('mtgo:process-matches')->assertSuccessful();

    // Events should be marked processed (from first loop)
    expect(LogEvent::where('match_token', 'dual-loop-test')->whereNull('processed_at')->count())->toBe(0);
});

it('does not process Ended matches in second loop when already handled in first', function () {
    $match = MtgoMatch::factory()->ended()->create([
        'token' => 'ended-first-loop',
        'mtgo_id' => '66666',
    ]);

    createPipelineLogEvent([
        'match_id' => '66666',
        'match_token' => 'ended-first-loop',
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'username' => 'testplayer',
    ]);

    mockMtgoManager();

    $this->artisan('mtgo:process-matches')->assertSuccessful();

    // The match was processed in the first loop, events marked processed
    expect(LogEvent::where('match_token', 'ended-first-loop')->whereNull('processed_at')->count())->toBe(0);
});
