<?php

use App\Actions\Logs\PruneProcessedLogEvents;
use App\Enums\MatchState;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes processed log events for completed matches', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'token' => 'completed-token',
    ]);

    LogEvent::factory()->count(3)->create([
        'match_token' => $match->token,
        'processed_at' => now(),
    ]);

    PruneProcessedLogEvents::run();

    expect(LogEvent::where('match_token', $match->token)->count())->toBe(0);
});

it('keeps processed events for matches not yet complete', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'token' => 'in-progress-token',
    ]);

    LogEvent::factory()->count(2)->create([
        'match_token' => $match->token,
        'processed_at' => now(),
    ]);

    PruneProcessedLogEvents::run();

    expect(LogEvent::where('match_token', $match->token)->count())->toBe(2);
});

it('hard-caps events older than 30 days regardless of processed state', function () {
    LogEvent::factory()->create([
        'match_token' => 'old-unprocessed',
        'processed_at' => null,
        'ingested_at' => now()->subDays(31),
    ]);

    LogEvent::factory()->create([
        'match_token' => 'recent-unprocessed',
        'processed_at' => null,
        'ingested_at' => now()->subDays(2),
    ]);

    PruneProcessedLogEvents::run();

    expect(LogEvent::where('match_token', 'old-unprocessed')->count())->toBe(0);
    expect(LogEvent::where('match_token', 'recent-unprocessed')->count())->toBe(1);
});
