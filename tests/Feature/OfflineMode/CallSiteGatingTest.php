<?php

use App\Exceptions\OfflineModeException;
use App\Facades\AppSettings;
use App\Jobs\ShipCardStats;
use App\Jobs\ShipTournamentObservations;
use App\Models\CardStatShipQueue;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use App\Models\TournamentObservationQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    AppSettings::setOffline(true);
    Http::preventStrayRequests();
});

it('does not transmit card stats while offline, and leaves the row claimable', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->for($match, 'match')->create(['won' => true]);

    $row = CardStatShipQueue::create([
        'game_id' => $game->id,
        'match_id' => $match->id,
        'payload' => json_encode(['won' => true]),
        'status' => 'pending',
    ]);

    (new ShipCardStats)->handle();

    Http::assertNothingSent();
    expect($row->fresh()->status)->toBe('pending');
    expect($row->fresh()->attempts)->toBe(0);
});

it('does not transmit tournament observations while offline, and leaves the row claimable', function () {
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
        'event_type' => 'tournament_sync',
        'tournament_token' => 'tok-1',
    ]);

    $observation = TournamentObservationQueue::create([
        'log_event_id' => $logEvent->id,
        'tournament_token' => 'tok-1',
        'event_type' => 'tournament_sync',
        'payload' => ['Token' => 'tok-1'],
        'client_observed_at' => now(),
        'status' => 'pending',
    ]);

    (new ShipTournamentObservations)->handle();

    Http::assertNothingSent();
    expect($observation->fresh()->status)->toBe('pending');
    expect($observation->fresh()->attempts)->toBe(0);
});

it('surfaces offline mode rather than a network error for community card stats', function () {
    expect(fn () => Http::mymtgoApi()->get('/api/card-stats'))
        ->toThrow(OfflineModeException::class);
});

it('still allows card identity requests while offline', function () {
    Http::fake([
        '*/api/cards/resolve' => Http::response(['cards' => []], 200),
    ]);

    $response = Http::mymtgoReference()->post('/api/cards/resolve', ['cards' => []]);

    expect($response->successful())->toBeTrue();
});
