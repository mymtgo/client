<?php

use App\Jobs\DecodeGameLogsJob;
use App\Jobs\DiscoverGameLogsJob;
use App\Models\DeckVersion;
use App\Models\GameLog;
use App\Models\ImportScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->scan = ImportScan::create([
        'deck_version_id' => DeckVersion::factory()->create()->id,
        'status' => 'processing',
        'stage' => 'discovering',
    ]);
});

it('discovers new game log files and creates records', function () {
    Queue::fake();

    // Create a temp directory with fake game log files
    $dir = sys_get_temp_dir().'/mtgo_test_'.uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/Match_GameLog_ABC123.dat', 'fake');
    file_put_contents($dir.'/Match_GameLog_DEF456.dat', 'fake');

    app()->instance('mtgo', new class($dir)
    {
        public function __construct(private string $dir) {}

        public function getLogDataPath(): string
        {
            return $this->dir;
        }
    });

    $job = new DiscoverGameLogsJob($this->scan->id);
    $job->handle();

    expect(GameLog::count())->toBe(2);
    expect(GameLog::where('match_token', 'ABC123')->exists())->toBeTrue();
    expect(GameLog::where('match_token', 'DEF456')->exists())->toBeTrue();

    $this->scan->refresh();
    expect($this->scan->progress)->toBe(2);
    expect($this->scan->total)->toBe(2);

    Queue::assertPushed(DecodeGameLogsJob::class, fn ($job) => $job->scanId === $this->scan->id);

    // Cleanup
    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

it('skips existing game log records', function () {
    Queue::fake();

    $dir = sys_get_temp_dir().'/mtgo_test_'.uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/Match_GameLog_ABC123.dat', 'fake');
    file_put_contents($dir.'/Match_GameLog_DEF456.dat', 'fake');

    // Pre-existing record
    GameLog::create(['match_token' => 'ABC123', 'file_path' => '/old/path.dat']);

    app()->instance('mtgo', new class($dir)
    {
        public function __construct(private string $dir) {}

        public function getLogDataPath(): string
        {
            return $this->dir;
        }
    });

    $job = new DiscoverGameLogsJob($this->scan->id);
    $job->handle();

    expect(GameLog::count())->toBe(2);
    // Only DEF456 is new
    expect(GameLog::where('match_token', 'DEF456')->exists())->toBeTrue();

    // Cleanup
    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

it('exits early when scan is cancelled', function () {
    Queue::fake();

    $this->scan->update(['status' => 'cancelled']);

    $job = new DiscoverGameLogsJob($this->scan->id);
    $job->handle();

    expect(GameLog::count())->toBe(0);
    Queue::assertNotPushed(DecodeGameLogsJob::class);
});

it('marks scan as failed via failed method', function () {
    $job = new DiscoverGameLogsJob($this->scan->id);
    $job->failed(new RuntimeException('disk error'));

    $this->scan->refresh();
    expect($this->scan->status)->toBe('failed');
    expect($this->scan->error)->toBe('disk error');
});
