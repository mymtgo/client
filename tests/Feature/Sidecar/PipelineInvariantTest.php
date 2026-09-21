<?php

use App\Actions\Pipeline\RunPipeline;
use App\Actions\Sidecar\IngestSidecarEvents;
use App\Actions\Sidecar\ProjectUnprocessedSidecarEvents;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Managers\MtgoManager;
use App\Models\GameEvent;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $mock = Mockery::mock(MtgoManager::class)->makePartial();
    $mock->shouldReceive('pathsAreValid')->andReturn(true);
    $mock->shouldReceive('ingestLogs')->andReturnNull();
    $mock->shouldReceive('getLogDataPath')->andReturn(sys_get_temp_dir());
    app()->instance('mtgo', $mock);
});

it('runs the sidecar ingester inside a pipeline tick', function () {
    $dir = sys_get_temp_dir().'/sidecar-pipe-'.uniqid();
    mkdir($dir);
    copy(base_path('tests/fixtures/sidecar/events-fixture.ndjson'), $dir.'/events-aaaaaaaa-0000-0000-0000-000000000001.ndjson');
    copy(base_path('tests/fixtures/sidecar/status.json'), $dir.'/status.json');
    AppSettings::setSidecarDirectory($dir);
    IngestSidecarEvents::resetTick();

    RunPipeline::run();

    expect(GameEvent::count())->toBe(30);

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

it('leaves the pipeline untouched when the sidecar directory is absent', function () {
    AppSettings::setSidecarDirectory(sys_get_temp_dir().'/nope-'.uniqid());

    RunPipeline::run();

    expect(GameEvent::count())->toBe(0);
});

it('does not let a malformed sidecar status.json abort the pipeline tick', function () {
    $dir = sys_get_temp_dir().'/sidecar-pipe-'.uniqid();
    mkdir($dir);
    copy(base_path('tests/fixtures/sidecar/events-fixture.ndjson'), $dir.'/events-aaaaaaaa-0000-0000-0000-000000000001.ndjson');
    file_put_contents($dir.'/status.json', json_encode(['heartbeat' => 'not-a-date', 'current_file' => 'x']));
    AppSettings::setSidecarDirectory($dir);
    IngestSidecarEvents::resetTick();

    // A stale in_progress match with no recent log activity proves a later
    // phase (AbandonStaleMatches) still ran after the guarded sidecar call.
    $match = MtgoMatch::create([
        'mtgo_id' => '999',
        'token' => 'tok-sidecar-guard',
        'format' => 'Modern',
        'match_type' => 'Swiss',
        'started_at' => now()->subHours(3),
        'state' => MatchState::InProgress,
    ]);

    LogEvent::create([
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => '/tmp/sidecar-guard.log',
        'byte_offset_start' => 1,
        'byte_offset_end' => 2,
        'timestamp' => now()->subMinutes(90)->format('H:i:s'),
        'level' => 'Info',
        'category' => 'Match',
        'context' => 'Match State Changed for tok-sidecar-guard from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState',
        'raw_text' => '(Game Management|Match State Changed for tok-sidecar-guard from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState)',
        'ingested_at' => now()->subMinutes(90),
        'logged_at' => now()->subMinutes(90),
        'processed_at' => now(),
        'match_token' => 'tok-sidecar-guard',
        'match_id' => '999',
        'event_type' => 'match_state_changed',
    ]);

    expect(fn () => RunPipeline::run())->not->toThrow(Throwable::class);

    expect($match->refresh()->state)->toBe(MatchState::Abandoned);

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

it('sweeps nothing when there are no sidecar events at all', function () {
    AppSettings::setSidecarDirectory(sys_get_temp_dir().'/nope-'.uniqid());

    DB::enableQueryLog();
    expect(ProjectUnprocessedSidecarEvents::run())->toBe(0)
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();

    RunPipeline::run();

    expect(ProjectUnprocessedSidecarEvents::run())->toBe(0)
        ->and(GameEvent::count())->toBe(0);
});
