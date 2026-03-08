<?php

use App\Actions\Logs\IngestLog;
use App\Models\Account;
use App\Models\LogCursor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('detects username from MtGO Login Success event', function () {
    $logContent = "15:52:41 [INF] (Login|MtGO Login Success) Username: testplayer (3022021)\n";
    $tmpFile = tempnam(sys_get_temp_dir(), 'mtgo_test_');
    file_put_contents($tmpFile, $logContent);

    IngestLog::run($tmpFile);

    $cursor = LogCursor::where('file_path', $tmpFile)->first();
    expect($cursor->local_username)->toBe('testplayer');

    unlink($tmpFile);
});

it('updates username when account switches mid-session', function () {
    $logContent = "15:52:41 [INF] (Login|MtGO Login Success) Username: player_one (3022021)\n";
    $tmpFile = tempnam(sys_get_temp_dir(), 'mtgo_test_');
    file_put_contents($tmpFile, $logContent);

    IngestLog::run($tmpFile);

    $cursor = LogCursor::where('file_path', $tmpFile)->first();
    expect($cursor->local_username)->toBe('player_one');

    // Append second login
    file_put_contents($tmpFile, "16:10:00 [INF] (Login|MtGO Login Success) Username: player_two (4055032)\n", FILE_APPEND);

    IngestLog::run($tmpFile);

    $cursor->refresh();
    expect($cursor->local_username)->toBe('player_two');

    unlink($tmpFile);
});

it('registers new accounts on login detection', function () {
    $logContent = "15:52:41 [INF] (Login|MtGO Login Success) Username: newplayer (3022021)\n";
    $tmpFile = tempnam(sys_get_temp_dir(), 'mtgo_test_');
    file_put_contents($tmpFile, $logContent);

    IngestLog::run($tmpFile);

    $account = Account::where('username', 'newplayer')->first();
    expect($account)->not->toBeNull();
    expect($account->tracked)->toBeTrue();
    expect($account->active)->toBeTrue();

    unlink($tmpFile);
});
