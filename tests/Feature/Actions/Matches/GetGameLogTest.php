<?php

use App\Actions\Matches\GetGameLog;
use App\Models\Account;
use App\Models\GameLog;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns null when no game log record exists', function () {
    Account::registerAndActivate('anticloser');

    expect(GetGameLog::run('nonexistent-token'))->toBeNull();
});

it('parses and stores decoded entries on first access', function () {
    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_0_win.dat');
    $log = GameLog::create([
        'match_token' => 'test-token-123',
        'file_path' => $fixturePath,
    ]);

    LogEvent::factory()->create([
        'match_token' => 'test-token-123',
        'username' => 'anticloser',
    ]);

    $result = GetGameLog::run('test-token-123');

    expect($result)->not->toBeNull();
    expect($result['results'])->toBe([true, true]);

    // Verify entries were stored
    $log->refresh();
    expect($log->decoded_entries)->not->toBeNull();
    expect($log->decoded_entries)->toHaveCount(253);
    expect($log->byte_offset)->toBeGreaterThan(0);
    expect($log->decoded_version)->toBe(1);
});

it('uses stored entries on subsequent access without re-reading file', function () {
    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_0_win.dat');
    $log = GameLog::create([
        'match_token' => 'test-token-456',
        'file_path' => $fixturePath,
    ]);

    LogEvent::factory()->create([
        'match_token' => 'test-token-456',
        'username' => 'anticloser',
    ]);

    // First call: parses and stores
    GetGameLog::run('test-token-456');
    $log->refresh();

    // Point file_path to nonexistent file to prove we use stored entries
    $log->update(['file_path' => '/nonexistent/path.dat']);

    // Second call: uses stored entries (file doesn't exist but we have entries)
    $result = GetGameLog::run('test-token-456');
    expect($result)->not->toBeNull();
    expect($result['results'])->toBe([true, true]);
});

it('returns results in backward-compatible format', function () {
    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_1_win.dat');
    GameLog::create([
        'match_token' => 'test-token-789',
        'file_path' => $fixturePath,
    ]);

    LogEvent::factory()->create([
        'match_token' => 'test-token-789',
        'username' => 'anticloser',
    ]);

    $result = GetGameLog::run('test-token-789');

    // Must have the three keys that downstream consumers expect
    expect($result)->toHaveKeys(['results', 'on_play', 'starting_hands']);
    expect($result['results'])->toBeArray();
    expect($result['on_play'])->toBeArray();
    expect($result['starting_hands'])->toBeArray();

    // results are booleans
    foreach ($result['results'] as $r) {
        expect($r)->toBeBool();
    }
});
