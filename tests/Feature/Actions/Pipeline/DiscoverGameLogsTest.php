<?php

use App\Actions\Pipeline\DiscoverGameLogs;
use App\Enums\MatchState;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->testDir = storage_path('test-gamelogs');
    File::ensureDirectoryExists($this->testDir);
});

afterEach(function () {
    File::deleteDirectory($this->testDir);
});

it('creates GameLog records for matching files', function () {
    MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'token' => 'abc123',
    ]);

    File::put($this->testDir.'/Match_GameLog_abc123.dat', 'binary data');

    DiscoverGameLogs::run($this->testDir);

    expect(GameLog::where('match_token', 'abc123')->exists())->toBeTrue();
});

it('skips files with no matching active match', function () {
    File::put($this->testDir.'/Match_GameLog_nomatch.dat', 'binary data');

    DiscoverGameLogs::run($this->testDir);

    expect(GameLog::count())->toBe(0);
});

it('is idempotent — does not duplicate GameLog records', function () {
    MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'token' => 'abc123',
    ]);

    File::put($this->testDir.'/Match_GameLog_abc123.dat', 'binary data');

    DiscoverGameLogs::run($this->testDir);
    DiscoverGameLogs::run($this->testDir);

    expect(GameLog::where('match_token', 'abc123')->count())->toBe(1);
});
