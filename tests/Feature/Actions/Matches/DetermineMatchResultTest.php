<?php

use App\Actions\Matches\DetermineMatchResult;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not inflate results on concession', function () {
    $logResults = [true]; // 1-0
    $stateChanges = collect([
        LogEvent::create([
            'file_path' => '/tmp/test.log',
            'byte_offset_start' => 0,
            'byte_offset_end' => 100,
            'timestamp' => now(),
            'level' => 'INFO',
            'category' => 'MatchPlugin',
            'context' => 'LeagueMatchConcedeReqState to LeagueMatchNotJoinedCatchAllState',
            'raw_text' => 'test',
            'ingested_at' => now(),
            'logged_at' => now(),
            'event_type' => 'match_state_changed',
        ]),
    ]);
    $result = DetermineMatchResult::run($logResults, $stateChanges);
    expect($result['wins'])->toBe(1)
        ->and($result['losses'])->toBe(0)
        ->and($result['decided'])->toBeTrue();
});

it('marks decided when win threshold reached', function () {
    $result = DetermineMatchResult::run([true, false, true], collect());
    expect($result['wins'])->toBe(2)
        ->and($result['losses'])->toBe(1)
        ->and($result['decided'])->toBeTrue();
});

it('marks decided when match score present', function () {
    $result = DetermineMatchResult::run([true], collect(), matchScoreExists: true);
    expect($result['decided'])->toBeTrue();
});

it('marks not decided when no signal exists', function () {
    $result = DetermineMatchResult::run([true], collect());
    expect($result['wins'])->toBe(1)
        ->and($result['losses'])->toBe(0)
        ->and($result['decided'])->toBeFalse();
});

it('marks decided on disconnect', function () {
    $result = DetermineMatchResult::run([true], collect(), disconnectDetected: true);
    expect($result['decided'])->toBeTrue();
});
