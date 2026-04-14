<?php

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Managers\MtgoManager;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createPipelineLogEvent(array $attributes = []): LogEvent
{
    return LogEvent::create(array_merge([
        'file_path' => '/tmp/test.log',
        'byte_offset_start' => rand(0, 999999),
        'byte_offset_end' => rand(1000000, 9999999),
        'timestamp' => now(),
        'level' => 'Info',
        'category' => 'Test',
        'context' => 'TestContext',
        'raw_text' => 'test log line',
        'ingested_at' => now(),
        'logged_at' => now(),
        'processed_at' => null,
    ], $attributes));
}

function mockMtgoManager(): void
{
    $tempDir = sys_get_temp_dir().'/mtgo_test_'.uniqid();
    @mkdir($tempDir, 0755, true);

    $mock = Mockery::mock(MtgoManager::class)->makePartial();
    $mock->shouldReceive('pathsAreValid')->andReturn(true);
    $mock->shouldReceive('ingestLogs')->andReturnNull();
    $mock->shouldReceive('getLogDataPath')->andReturn($tempDir);

    app()->instance('mtgo', $mock);
}

it('runs without error when there is no work', function () {
    mockMtgoManager();

    $this->artisan('mtgo:process-matches')->assertSuccessful();
});

it('skips matches with failed_at set', function () {
    mockMtgoManager();

    $match = MtgoMatch::factory()->inProgress()->failed()->create();

    createPipelineLogEvent([
        'match_id' => $match->mtgo_id,
        'match_token' => $match->token,
        'event_type' => 'game_state_update',
    ]);

    $this->artisan('mtgo:process-matches')->assertSuccessful();

    // Events should still be unprocessed — match was skipped
    expect(LogEvent::whereNull('processed_at')->count())->toBe(1);
});

it('marks events as processed after match processing', function () {
    mockMtgoManager();

    $match = MtgoMatch::factory()->inProgress()->create([
        'token' => 'test-token',
        'mtgo_id' => '12345',
    ]);

    // Join event required by AdvanceMatchState
    createPipelineLogEvent([
        'match_id' => '12345',
        'match_token' => 'test-token',
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'username' => 'testplayer',
    ]);

    createPipelineLogEvent([
        'match_id' => '12345',
        'match_token' => 'test-token',
        'event_type' => 'game_state_update',
        'username' => 'testplayer',
    ]);

    $this->artisan('mtgo:process-matches')->assertSuccessful();

    expect(LogEvent::where('match_token', 'test-token')->whereNull('processed_at')->count())->toBe(0);
});

it('does not increment attempts for missing game log', function () {
    mockMtgoManager();

    $match = MtgoMatch::factory()->inProgress()->create(['attempts' => 0]);

    $this->artisan('mtgo:process-matches')->assertSuccessful();

    expect($match->fresh()->attempts)->toBe(0);
});

it('is idempotent — running twice produces same result', function () {
    mockMtgoManager();

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
    ]);

    $this->artisan('mtgo:process-matches')->assertSuccessful();
    $this->artisan('mtgo:process-matches')->assertSuccessful();

    expect($match->fresh()->state)->toBe(MatchState::Complete);
    expect($match->fresh()->outcome)->toBe(MatchOutcome::Win);
});
