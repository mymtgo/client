<?php

use App\Actions\Matches\ParseGameLogBinary;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Jobs\PollGameLogs;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has a match relationship on GameLog', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::InProgress]);

    $log = GameLog::create([
        'match_token' => $match->token,
        'file_path' => '/fake/path.dat',
    ]);

    expect($log->match)->toBeInstanceOf(MtgoMatch::class);
    expect($log->match->id)->toBe($match->id);
});

it('handles missing Mtgo log data path gracefully', function () {
    Mtgo::shouldReceive('getLogDataPath')->once()->andReturn('');

    // Should not throw
    PollGameLogs::dispatchSync();
});

it('handles non-existent Mtgo log data path gracefully', function () {
    Mtgo::shouldReceive('getLogDataPath')->once()->andReturn('/nonexistent/path');

    PollGameLogs::dispatchSync();
});

it('skips complete matches during discovery', function () {
    $basePath = sys_get_temp_dir().'/poll-game-logs-test-'.uniqid();
    mkdir($basePath, 0777, true);

    $completeMatch = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'token' => 'complete-token',
    ]);

    // Create a .dat file that matches the token
    file_put_contents($basePath.'/Match_GameLog_complete-token.dat', 'dummy');

    Mtgo::shouldReceive('getLogDataPath')->once()->andReturn($basePath);

    PollGameLogs::dispatchSync();

    expect(GameLog::where('match_token', 'complete-token')->exists())->toBeFalse();

    // Cleanup
    @unlink($basePath.'/Match_GameLog_complete-token.dat');
    @rmdir($basePath);
});

it('skips voided matches during discovery', function () {
    $basePath = sys_get_temp_dir().'/poll-game-logs-test-'.uniqid();
    mkdir($basePath, 0777, true);

    MtgoMatch::factory()->create([
        'state' => MatchState::Voided,
        'token' => 'voided-token',
    ]);

    file_put_contents($basePath.'/Match_GameLog_voided-token.dat', 'dummy');

    Mtgo::shouldReceive('getLogDataPath')->once()->andReturn($basePath);

    PollGameLogs::dispatchSync();

    expect(GameLog::where('match_token', 'voided-token')->exists())->toBeFalse();

    @unlink($basePath.'/Match_GameLog_voided-token.dat');
    @rmdir($basePath);
});

it('discovers game logs for active matches', function () {
    $basePath = sys_get_temp_dir().'/poll-game-logs-test-'.uniqid();
    mkdir($basePath, 0777, true);

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'token' => 'active-token',
    ]);

    // Create a minimal valid .dat file using a real fixture
    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_0_win.dat');
    copy($fixturePath, $basePath.'/Match_GameLog_active-token.dat');

    Mtgo::shouldReceive('getLogDataPath')->once()->andReturn($basePath);

    PollGameLogs::dispatchSync();

    $log = GameLog::where('match_token', 'active-token')->first();
    expect($log)->not->toBeNull();
    expect($log->file_path)->toContain('active-token.dat');

    @unlink($basePath.'/Match_GameLog_active-token.dat');
    @rmdir($basePath);
});

it('parses game log files for active matches', function () {
    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_0_win.dat');

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'token' => 'parse-token',
    ]);

    $log = GameLog::create([
        'match_token' => 'parse-token',
        'file_path' => $fixturePath,
        'byte_offset' => 0,
    ]);

    // No discovery needed — just parsing
    Mtgo::shouldReceive('getLogDataPath')->once()->andReturn('/nonexistent');

    PollGameLogs::dispatchSync();

    $log->refresh();
    expect($log->decoded_entries)->not->toBeNull();
    expect($log->decoded_entries)->toBeArray();
    expect($log->decoded_entries)->not->toBeEmpty();
    expect($log->byte_offset)->toBeGreaterThan(0);
    expect($log->decoded_version)->toBe(ParseGameLogBinary::VERSION);
    expect($log->decoded_at)->not->toBeNull();
});

it('skips parsing when file has not grown', function () {
    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_0_win.dat');
    $fileSize = filesize($fixturePath);

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'token' => 'no-growth-token',
    ]);

    $log = GameLog::create([
        'match_token' => 'no-growth-token',
        'file_path' => $fixturePath,
        'byte_offset' => $fileSize, // Already fully parsed
        'decoded_entries' => [['timestamp' => 'old', 'message' => 'old']],
        'decoded_at' => now()->subMinute(),
    ]);

    Mtgo::shouldReceive('getLogDataPath')->once()->andReturn('/nonexistent');

    PollGameLogs::dispatchSync();

    $log->refresh();
    // Should not have re-parsed — decoded_entries unchanged
    expect($log->decoded_entries)->toBe([['timestamp' => 'old', 'message' => 'old']]);
});

it('skips parsing when file is missing', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'token' => 'missing-file-token',
    ]);

    $log = GameLog::create([
        'match_token' => 'missing-file-token',
        'file_path' => '/nonexistent/file.dat',
        'byte_offset' => 0,
    ]);

    Mtgo::shouldReceive('getLogDataPath')->once()->andReturn('/nonexistent');

    PollGameLogs::dispatchSync();

    $log->refresh();
    expect($log->decoded_entries)->toBeNull();
});

it('does full re-parse when file grows', function () {
    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_0_win.dat');

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'token' => 'regrow-token',
    ]);

    // Simulate a partially parsed log (byte_offset < file size)
    $log = GameLog::create([
        'match_token' => 'regrow-token',
        'file_path' => $fixturePath,
        'byte_offset' => 100, // Less than actual file size
        'decoded_entries' => [['timestamp' => 'partial', 'message' => 'partial']],
        'decoded_version' => ParseGameLogBinary::VERSION,
    ]);

    Mtgo::shouldReceive('getLogDataPath')->once()->andReturn('/nonexistent');

    PollGameLogs::dispatchSync();

    $log->refresh();
    // Full re-parse replaces old entries
    expect($log->decoded_entries)->toBeArray();
    expect(count($log->decoded_entries))->toBeGreaterThan(1);
    expect($log->decoded_entries[0]['message'])->not->toBe('partial');
});
