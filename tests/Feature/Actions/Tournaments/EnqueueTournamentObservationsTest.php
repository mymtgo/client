<?php

use App\Actions\Tournaments\EnqueueTournamentObservations;
use App\Enums\LogEventType;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\TournamentObservationQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeClassifiedLogEvent(string $eventType, ?string $tournamentToken, ?string $matchToken, string $rawText): LogEvent
{
    return LogEvent::create([
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => strlen($rawText),
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Tournament',
        'context' => '',
        'raw_text' => $rawText,
        'ingested_at' => now(),
        'logged_at' => now(),
        'event_type' => $eventType,
        'tournament_token' => $tournamentToken,
        'match_token' => $matchToken,
    ]);
}

it('enqueues an observation for a newly classified tournament event', function () {
    $event = makeClassifiedLogEvent(
        LogEventType::TOURNAMENT_SYNC->value,
        'tok-1',
        null,
        '12:00:00 [INF] (Tournament|Sync) EventSyncData_t {"Token":"tok-1"}'
    );

    EnqueueTournamentObservations::run();

    expect(TournamentObservationQueue::count())->toBe(1);
    $row = TournamentObservationQueue::first();
    expect($row->log_event_id)->toBe($event->id);
    expect($row->tournament_token)->toBe('tok-1');
    expect($row->event_type)->toBe(LogEventType::TOURNAMENT_SYNC->value);
    expect($row->status)->toBe('pending');
    expect($row->payload)->toMatchArray(['Token' => 'tok-1']);
});

it('skips log events that are already enqueued', function () {
    $event = makeClassifiedLogEvent(
        LogEventType::TOURNAMENT_SYNC->value,
        'tok-1',
        null,
        '12:00:00 [INF] (Tournament|Sync) EventSyncData_t {"Token":"tok-1"}'
    );

    EnqueueTournamentObservations::run();
    EnqueueTournamentObservations::run();

    expect(TournamentObservationQueue::count())->toBe(1);
});

it('ignores non-tournament log events', function () {
    LogEvent::create([
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => 10,
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Game Management',
        'context' => '',
        'raw_text' => 'something',
        'ingested_at' => now(),
        'logged_at' => now(),
        'event_type' => 'match_state_changed',
        'match_token' => 'abc',
    ]);

    EnqueueTournamentObservations::run();

    expect(TournamentObservationQueue::count())->toBe(0);
});

it('skips events whose extracted payload is empty', function () {
    // A tournament_state_changed event whose raw_text does NOT match the
    // extractor regex. Extraction returns [], so the row must not be enqueued
    // — the API would 422 on an empty payload.
    LogEvent::factory()->create([
        'event_type' => LogEventType::TOURNAMENT_STATE_CHANGED->value,
        'raw_text' => 'garbage line with no from/to',
        'tournament_token' => 'deadbeef-dead-beef-dead-beefdeadbeef',
    ]);

    $inserted = EnqueueTournamentObservations::run();

    expect($inserted)->toBe(0);
    expect(TournamentObservationQueue::count())->toBe(0);
});
