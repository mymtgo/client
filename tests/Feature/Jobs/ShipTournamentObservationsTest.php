<?php

use App\Facades\AppSettings;
use App\Jobs\ShipTournamentObservations;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\TournamentObservationQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Reset the global Http::fake() stub from Pest.php so test-specific fakes take priority.
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    AppSettings::setDeviceId('test-device');
    AppSettings::setApiKey('test-key');
});

function enqueueObservation(string $eventType = 'tournament_sync'): TournamentObservationQueue
{
    $logEvent = LogEvent::create([
        'log_instance_id' => LogInstance::factory()->create()->id,
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

it('sends a body that satisfies the API validator: array of observations with required fields', function () {
    enqueueObservation('tournament_state_changed');

    Http::fake([
        '*/api/tournament-observations' => Http::response('', 204),
        '*' => Http::response('', 200),
    ]);

    (new ShipTournamentObservations)->handle();

    Http::assertSent(function ($request) {
        $decoded = gzdecode($request->body());
        expect($decoded)->not->toBeFalse();

        $body = json_decode($decoded, true);
        expect($body)->toBeArray()->toHaveCount(1);

        $obs = $body[0];
        expect($obs)->toHaveKeys([
            'tournament_token',
            'match_token',
            'event_type',
            'payload',
            'client_observed_at',
        ]);

        expect($obs['tournament_token'])->toBe('tok-1');
        expect($obs['event_type'])->toBe('tournament_state_changed');
        expect($obs['payload'])->toBeArray()->not->toBeEmpty();
        expect($obs['client_observed_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');

        return true;
    });
});
