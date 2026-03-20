<?php

use App\Actions\Pipeline\ArchiveGameLog;
use App\Models\GameLog;
use App\Models\GameLogCursor;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeMatchForArchive(string $token): MtgoMatch
{
    return MtgoMatch::create([
        'mtgo_id' => (string) rand(10000, 99999),
        'token' => $token,
        'format' => 'CModern',
        'match_type' => 'Constructed',
        'started_at' => now()->subHour(),
        'ended_at' => now(),
        'state' => \App\Enums\MatchState::Complete,
        'games_won' => 2,
        'games_lost' => 0,
    ]);
}

it('returns early and does not error when no .dat file is found', function () {
    $match = makeMatchForArchive('no-dat-file-token');

    // No GameLog or GameLogCursor record exists for this token
    ArchiveGameLog::run($match);

    // No record should have been created
    expect(GameLog::where('match_token', 'no-dat-file-token')->count())->toBe(0);
});

it('creates a GameLog record when a .dat file exists via GameLogCursor', function () {
    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_0_win.dat');
    $token = 'archive-test-token-'.uniqid();

    $match = makeMatchForArchive($token);

    // Register the file path via GameLogCursor (as StoreGameLogFiles would)
    GameLogCursor::create([
        'match_token' => $token,
        'file_path' => $fixturePath,
        'byte_offset' => 0,
    ]);

    ArchiveGameLog::run($match);

    $log = GameLog::where('match_token', $token)->first();
    expect($log)->not->toBeNull();
    expect($log->decoded_entries)->not->toBeEmpty();
    expect($log->decoded_at)->not->toBeNull();
});

it('creates a GameLog record when a .dat file is referenced in GameLog table', function () {
    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_1_win.dat');
    $token = 'archive-gamelog-token-'.uniqid();

    $match = makeMatchForArchive($token);

    // Register the file path via GameLog (as StoreGameLogFiles would)
    GameLog::create([
        'match_token' => $token,
        'file_path' => $fixturePath,
    ]);

    ArchiveGameLog::run($match);

    $log = GameLog::where('match_token', $token)->first();
    expect($log->decoded_entries)->not->toBeEmpty();
    expect($log->decoded_at)->not->toBeNull();
});

it('is idempotent and does not overwrite an existing archive', function () {
    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_0_win.dat');
    $token = 'idempotent-token-'.uniqid();

    $match = makeMatchForArchive($token);

    $existingEntries = [['timestamp' => '2024-01-01T00:00:00+00:00', 'message' => 'existing']];

    GameLog::create([
        'match_token' => $token,
        'file_path' => $fixturePath,
        'decoded_entries' => $existingEntries,
        'decoded_at' => now()->subHour(),
    ]);

    ArchiveGameLog::run($match);

    $log = GameLog::where('match_token', $token)->first();

    // Entries should remain as originally set (write-once)
    expect($log->decoded_entries)->toBe($existingEntries);
});

it('populates decoded entries on a GameLog record that has a file path but no entries', function () {
    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_0_win.dat');
    $token = 'populate-token-'.uniqid();

    $match = makeMatchForArchive($token);

    // GameLog exists but has no entries yet
    GameLog::create([
        'match_token' => $token,
        'file_path' => $fixturePath,
        'decoded_entries' => null,
    ]);

    ArchiveGameLog::run($match);

    $log = GameLog::where('match_token', $token)->first();
    expect($log->decoded_entries)->not->toBeEmpty();
    expect($log->decoded_at)->not->toBeNull();
});
