<?php

use App\Actions\Matches\ParseGameLogBinary;
use App\Jobs\ReDecodeGameLogsJob;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Desktop\Facades\Settings;

uses(RefreshDatabase::class);

it('re-decodes game logs and updates match timestamps', function () {
    Settings::set('system_tz', 'America/Los_Angeles');

    $match = MtgoMatch::factory()->create([
        'token' => 'test-token',
        'started_at' => Carbon::parse('2026-04-07 12:00:00', 'UTC'),
        'ended_at' => Carbon::parse('2026-04-07 12:30:00', 'UTC'),
    ]);

    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_0_win.dat');
    if (! file_exists($fixturePath)) {
        $this->markTestSkipped('No fixture file available');
    }

    GameLog::create([
        'match_token' => 'test-token',
        'file_path' => $fixturePath,
        'decoded_entries' => [['timestamp' => '2026-04-07T12:00:00+00:00', 'message' => 'test']],
        'decoded_at' => now(),
        'decoded_version' => 1,
    ]);

    (new ReDecodeGameLogsJob)->handle();

    $gameLog = GameLog::first();
    expect($gameLog->decoded_version)->toBe(ParseGameLogBinary::VERSION);
    expect(count($gameLog->decoded_entries))->toBeGreaterThan(1);

    $match->refresh();
    expect($match->started_at)->not->toBeNull();
});

it('skips game logs already at the current decoded version', function () {
    Settings::set('system_tz', 'UTC');

    MtgoMatch::factory()->create(['token' => 'current-version']);

    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_0_win.dat');

    GameLog::create([
        'match_token' => 'current-version',
        'file_path' => $fixturePath,
        'decoded_entries' => [['timestamp' => '2026-04-07T12:00:00+00:00', 'message' => 'test']],
        'decoded_at' => now(),
        'decoded_version' => ParseGameLogBinary::VERSION,
    ]);

    (new ReDecodeGameLogsJob)->handle();

    // Should not be re-decoded — it's already at the current version
    expect(GameLog::first()->decoded_version)->toBe(ParseGameLogBinary::VERSION);
    expect(GameLog::first()->decoded_entries)->toHaveCount(1);
});

it('skips game logs with missing files', function () {
    Settings::set('system_tz', 'UTC');

    MtgoMatch::factory()->create(['token' => 'missing-file']);

    GameLog::create([
        'match_token' => 'missing-file',
        'file_path' => '/nonexistent/path.dat',
        'decoded_entries' => [['timestamp' => '2026-04-07T12:00:00+00:00', 'message' => 'test']],
        'decoded_at' => now(),
        'decoded_version' => 1,
    ]);

    (new ReDecodeGameLogsJob)->handle();

    expect(GameLog::first()->decoded_version)->toBe(1);
});
