<?php

use App\Actions\Matches\EnsureGameLogForMatch;
use App\Facades\Mtgo;
use App\Models\GameLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns decoded entries from an existing decoded game log without touching disk', function () {
    Mtgo::shouldReceive('getLogDataPath')->andReturn('/nonexistent/path');

    GameLog::create([
        'match_token' => 'tok-existing',
        'file_path' => '/some/old/path.dat',
        'decoded_entries' => [
            ['timestamp' => '2026-05-26T10:00:00+00:00', 'message' => '@PAlice casts @[Bolt@:2,0:@]'],
        ],
    ]);

    $entries = EnsureGameLogForMatch::run('tok-existing');

    expect($entries)->toHaveCount(1);
    expect($entries[0]['message'])->toBe('@PAlice casts @[Bolt@:2,0:@]');
});

it('locates and decodes the .dat from disk when no decoded row exists', function () {
    $dir = sys_get_temp_dir().'/ensure-gamelog-'.uniqid();
    mkdir($dir);
    $token = 'tok-ondisk';
    copy(base_path('tests/fixtures/gamelogs/clean_2_0_win.dat'), $dir."/Match_GameLog_{$token}.dat");

    Mtgo::shouldReceive('getLogDataPath')->andReturn($dir);

    $entries = EnsureGameLogForMatch::run($token);

    expect($entries)->toHaveCount(253);

    $log = GameLog::where('match_token', $token)->first();
    expect($log)->not->toBeNull();
    expect($log->decoded_entries)->toHaveCount(253);

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

it('returns an empty array when no decoded row and no .dat on disk', function () {
    $dir = sys_get_temp_dir().'/ensure-gamelog-empty-'.uniqid();
    mkdir($dir);

    Mtgo::shouldReceive('getLogDataPath')->andReturn($dir);

    expect(EnsureGameLogForMatch::run('tok-missing'))->toBe([]);

    rmdir($dir);
});
