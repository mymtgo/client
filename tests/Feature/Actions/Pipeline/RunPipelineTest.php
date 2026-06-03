<?php

use App\Actions\Pipeline\RunPipeline;
use App\Enums\MatchState;
use App\Managers\MtgoManager;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $mock = Mockery::mock(MtgoManager::class)->makePartial();
    $mock->shouldReceive('pathsAreValid')->andReturn(true);
    $mock->shouldReceive('ingestLogs')->andReturnNull();
    $mock->shouldReceive('getLogDataPath')->andReturn(sys_get_temp_dir());
    app()->instance('mtgo', $mock);
});

it('abandons a stale in_progress match with no end signal during a pipeline tick', function () {
    $match = MtgoMatch::create([
        'mtgo_id' => '777',
        'token' => 'tok-pipeline',
        'format' => 'Modern',
        'match_type' => 'Swiss',
        'started_at' => now()->subHours(3),
        'state' => MatchState::InProgress,
    ]);

    LogEvent::create([
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => '/tmp/pipeline.log',
        'byte_offset_start' => 1,
        'byte_offset_end' => 2,
        'timestamp' => now()->subMinutes(90)->format('H:i:s'),
        'level' => 'Info',
        'category' => 'Match',
        'context' => 'Match State Changed for tok-pipeline from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState',
        'raw_text' => '(Game Management|Match State Changed for tok-pipeline from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState)',
        'ingested_at' => now()->subMinutes(90),
        'logged_at' => now()->subMinutes(90),
        'processed_at' => now(),
        'match_token' => 'tok-pipeline',
        'match_id' => '777',
        'event_type' => 'match_state_changed',
    ]);

    RunPipeline::run();

    expect($match->refresh()->state)->toBe(MatchState::Abandoned);
});
