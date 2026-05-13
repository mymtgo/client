<?php

use App\Actions\Pipeline\DiscoverGameLogs;
use App\Models\GameLog;
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

it('discovers all game logs regardless of match state', function () {
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
