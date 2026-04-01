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

it('handles invalid binary data gracefully during decode', function () {
    MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'token' => 'baddata',
    ]);

    File::put($this->testDir.'/Match_GameLog_baddata.dat', 'not valid binary');

    DiscoverGameLogs::run($this->testDir);

    $gameLog = GameLog::where('match_token', 'baddata')->first();
    expect($gameLog)->not->toBeNull()
        ->and($gameLog->decoded_entries)->toBeNull();
});

it('discovers all game logs regardless of match state', function () {
    // No active matches — run() would find nothing
    File::put($this->testDir.'/Match_GameLog_hist1.dat', 'data');
    File::put($this->testDir.'/Match_GameLog_hist2.dat', 'data');
    File::put($this->testDir.'/Match_GameLog_hist3.dat', 'data');

    $discovered = DiscoverGameLogs::discoverAll($this->testDir);

    expect($discovered)->toBe(3)
        ->and(GameLog::count())->toBe(3);
});

it('discoverAll is idempotent', function () {
    File::put($this->testDir.'/Match_GameLog_idem1.dat', 'data');

    $first = DiscoverGameLogs::discoverAll($this->testDir);
    $second = DiscoverGameLogs::discoverAll($this->testDir);

    expect($first)->toBe(1)
        ->and($second)->toBe(0)
        ->and(GameLog::count())->toBe(1);
});

it('discoverAll returns zero for empty directory', function () {
    $discovered = DiscoverGameLogs::discoverAll($this->testDir);

    expect($discovered)->toBe(0)
        ->and(GameLog::count())->toBe(0);
});
