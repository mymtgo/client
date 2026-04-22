<?php

use App\Enums\MatchState;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('nulls failed_at and resets attempts for matches that were permanently abandoned under the old classifier', function () {
    // Seed: an abandoned match and a healthy one. Only the abandoned one should be touched.
    $abandoned = MtgoMatch::create([
        'mtgo_id' => 'abandoned-1',
        'token' => 'tok-abandoned',
        'format' => 'Modern',
        'match_type' => 'Swiss',
        'started_at' => now()->subHour(),
        'state' => MatchState::InProgress,
        'attempts' => 5,
        'failed_at' => now()->subMinutes(30),
    ]);

    $healthy = MtgoMatch::create([
        'mtgo_id' => 'healthy-1',
        'token' => 'tok-healthy',
        'format' => 'Modern',
        'match_type' => 'Swiss',
        'started_at' => now()->subHour(),
        'state' => MatchState::Complete,
        'attempts' => 0,
        'failed_at' => null,
    ]);

    // Re-run the recovery migration explicitly (RefreshDatabase has already run all
    // migrations once in setUp; calling it directly re-executes the up() closure
    // against the already-migrated schema, which is harmless for an UPDATE-only
    // migration).
    $migrationPath = database_path('migrations/2026_04_22_000000_reset_failed_at_for_transient_abandonment_recovery.php');
    $migration = require $migrationPath;
    $migration->up();

    $abandoned->refresh();
    $healthy->refresh();

    expect($abandoned->failed_at)->toBeNull();
    expect($abandoned->attempts)->toBe(0);

    // Healthy match untouched — attempts was already 0, failed_at was already null.
    expect($healthy->failed_at)->toBeNull();
    expect($healthy->attempts)->toBe(0);
});

it('clears failed_at on matches that already reached Complete but had failed_at set by a prior bug', function () {
    $completeButFailed = MtgoMatch::create([
        'mtgo_id' => 'complete-1',
        'token' => 'tok-complete',
        'format' => 'Modern',
        'match_type' => 'Swiss',
        'started_at' => now()->subDay(),
        'state' => MatchState::Complete,
        'attempts' => 5,
        'failed_at' => now()->subHours(6),
    ]);

    $migrationPath = database_path('migrations/2026_04_22_000000_reset_failed_at_for_transient_abandonment_recovery.php');
    $migration = require $migrationPath;
    $migration->up();

    $completeButFailed->refresh();

    expect($completeButFailed->failed_at)->toBeNull();
    expect($completeButFailed->attempts)->toBe(0);
});

it('leaves attempts untouched on matches with no failed_at', function () {
    $inFlight = MtgoMatch::create([
        'mtgo_id' => 'inflight-1',
        'token' => 'tok-inflight',
        'format' => 'Modern',
        'match_type' => 'Swiss',
        'started_at' => now()->subMinutes(5),
        'state' => MatchState::InProgress,
        'attempts' => 2,
        'failed_at' => null,
    ]);

    $migrationPath = database_path('migrations/2026_04_22_000000_reset_failed_at_for_transient_abandonment_recovery.php');
    $migration = require $migrationPath;
    $migration->up();

    $inFlight->refresh();

    expect($inFlight->attempts)->toBe(2);
    expect($inFlight->failed_at)->toBeNull();
});
