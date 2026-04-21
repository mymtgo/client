<?php

use App\Jobs\ShipTournamentObservations;
use App\Models\LogEvent;
use App\Models\TournamentObservationQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Facades\Settings;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Reset the global Http::fake() stub from Pest.php so test-specific fakes take priority.
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    Settings::set('device_id', 'test-device');
    Settings::set('api_key', Crypt::encrypt('test-key'));
});

function enqueueObservation(string $eventType = 'tournament_sync'): TournamentObservationQueue
{
    $logEvent = LogEvent::create([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => 10,
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Tournament',
        'context' => '',
        'raw_text' => 'x',
        'ingested_at' => now(),
        'logged_at' => now(),
        'event_type' => $eventType,
        'tournament_token' => 'tok-1',
    ]);

    return TournamentObservationQueue::create([
        'log_event_id' => $logEvent->id,
        'tournament_token' => 'tok-1',
        'event_type' => $eventType,
        'payload' => ['Token' => 'tok-1'],
        'client_observed_at' => now(),
        'status' => 'pending',
    ]);
}

it('marks observations as sent on 204 response', function () {
    $obs = enqueueObservation();

    Http::fake([
        '*/api/tournament-observations' => Http::response('', 204),
        '*' => Http::response('', 200),
    ]);

    (new ShipTournamentObservations)->handle();

    expect($obs->fresh()->status)->toBe('sent');
    expect($obs->fresh()->attempts)->toBe(1);
});

it('sends gzipped body with auth headers', function () {
    enqueueObservation();

    Http::fake([
        '*/api/tournament-observations' => Http::response('', 204),
        '*' => Http::response('', 200),
    ]);

    (new ShipTournamentObservations)->handle();

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Device-Id', 'test-device')
            && $request->hasHeader('X-Api-Key', 'test-key')
            && $request->hasHeader('Content-Encoding', 'gzip')
            && strlen($request->body()) > 0;
    });
});

it('flips failed observations back to pending with backoff', function () {
    $obs = enqueueObservation();

    Http::fake([
        '*/api/tournament-observations' => Http::response('server error', 500),
        '*' => Http::response('', 200),
    ]);

    (new ShipTournamentObservations)->handle();

    $obs->refresh();
    expect($obs->status)->toBe('pending');
    expect($obs->attempts)->toBe(1);
    expect($obs->next_attempt_at)->not->toBeNull();
    expect($obs->last_error)->toContain('500');
});

it('marks observations failed after 20 attempts', function () {
    $obs = enqueueObservation();
    $obs->update(['attempts' => 19, 'next_attempt_at' => now()->subMinute()]);

    Http::fake([
        '*/api/tournament-observations' => Http::response('server error', 500),
        '*' => Http::response('', 200),
    ]);

    (new ShipTournamentObservations)->handle();

    expect($obs->fresh()->status)->toBe('failed');
    expect($obs->fresh()->attempts)->toBe(20);
});

it('skips observations whose next_attempt_at is in the future', function () {
    $obs = enqueueObservation();
    $obs->update(['next_attempt_at' => now()->addMinutes(5)]);

    Http::fake([
        '*/api/tournament-observations' => Http::response('', 204),
        '*' => Http::response('', 200),
    ]);

    (new ShipTournamentObservations)->handle();

    expect($obs->fresh()->status)->toBe('pending');
    expect($obs->fresh()->attempts)->toBe(0);
    Http::assertNothingSent();
});
