<?php

use App\Actions\Archetypes\ResolveCardsFromDek;
use App\Actions\Cards\EnqueueCardStats;
use App\Actions\Tournaments\EnqueueTournamentObservations;
use App\Enums\LogEventType;
use App\Facades\AppSettings;
use App\Jobs\ShipCardStats;
use App\Jobs\ShipTournamentObservations;
use App\Managers\MtgoManager;
use App\Models\CardStatShipQueue;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\TournamentObservationQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\CardStatsTelemetryFactory;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A valid, unexpired key is load-bearing: without it, RegisterDevice::ensureFresh()
    // fires a /api/devices/register request the moment any mymtgoReference() consumer
    // runs, and assertNothingSent() would fail for the wrong reason.
    AppSettings::setApiKey('valid-key');
    AppSettings::setApiKeyExpiresAt(now()->addDay()->toIso8601String());

    // Harmless belt-and-suspenders: Pest.php installs a bare catch-all Http::fake()
    // ahead of this, so nothing is ever actually "stray" here. assertNothingSent()
    // below is what does the real work, reading the request log independently.
    Http::preventStrayRequests();
});

/**
 * A tournament log event that ExtractTournamentPayload can turn into a
 * non-empty payload, so EnqueueTournamentObservations has a row it would
 * queue if the offline guard were not there. Mirrors the fixture proven
 * non-vacuous in SchedulerGatingTest.
 */
function eligibleTournamentSyncEvent(): LogEvent
{
    $rawText = '12:00:00 [INF] (Tournament|Sync) EventSyncData_t {"Token":"tok-1"}';

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
        'event_type' => LogEventType::TOURNAMENT_SYNC->value,
        'tournament_token' => 'tok-1',
    ]);
}

it('sends nothing community-derived while offline, even with fixtures that would otherwise transmit', function () {
    AppSettings::setOffline(true);

    // A Complete match with a resolved deck, archetypes on both sides, and
    // non-opponent card stats — the exact shape EnqueueCardStats would
    // enqueue, and (via submittable()) that retryUnsubmittedMatches would
    // dispatch for submission.
    $telemetry = CardStatsTelemetryFactory::make();

    // A row already sitting in the ship queue — bypassing EnqueueCardStats
    // entirely — so ShipCardStats::handle() has real work it would send if
    // its own offline guard were removed.
    CardStatShipQueue::create([
        'game_id' => $telemetry['games'][0]->id,
        'match_id' => $telemetry['match']->id,
        'payload' => ['games' => []],
        'status' => 'pending',
    ]);

    // An eligible tournament sync event...
    $tournamentEvent = eligibleTournamentSyncEvent();

    // ...and a row already queued for shipping, bypassing
    // EnqueueTournamentObservations, so ShipTournamentObservations::handle()
    // has real work too.
    TournamentObservationQueue::create([
        'log_event_id' => $tournamentEvent->id,
        'tournament_token' => 'tok-1',
        'match_token' => null,
        'event_type' => LogEventType::TOURNAMENT_SYNC->value,
        'payload' => ['token' => 'tok-1'],
        'client_observed_at' => now(),
        'status' => 'pending',
    ]);

    (new ShipCardStats)->handle();
    (new ShipTournamentObservations)->handle();
    EnqueueCardStats::run();
    EnqueueTournamentObservations::run();
    app(MtgoManager::class)->retryUnsubmittedMatches();

    Http::assertNothingSent();
});

it('leaves the outbound queues empty while offline, for fixtures proven eligible below', function () {
    AppSettings::setOffline(true);

    CardStatsTelemetryFactory::make();
    eligibleTournamentSyncEvent();

    EnqueueCardStats::run();
    EnqueueTournamentObservations::run();

    expect(CardStatShipQueue::count())->toBe(0)
        ->and(TournamentObservationQueue::count())->toBe(0);
});

it('fills the outbound queues for the same fixtures while online (control)', function () {
    AppSettings::setOffline(false);

    CardStatsTelemetryFactory::make();
    eligibleTournamentSyncEvent();

    EnqueueCardStats::run();
    EnqueueTournamentObservations::run();

    expect(CardStatShipQueue::count())->toBeGreaterThan(0)
        ->and(TournamentObservationQueue::count())->toBeGreaterThan(0);
});

it('ships already-queued card stats while online (control)', function () {
    AppSettings::setOffline(false);

    $telemetry = CardStatsTelemetryFactory::make();
    CardStatShipQueue::create([
        'game_id' => $telemetry['games'][0]->id,
        'match_id' => $telemetry['match']->id,
        'payload' => ['games' => []],
        'status' => 'pending',
    ]);

    (new ShipCardStats)->handle();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/card-stats/report'));
});

it('ships already-queued tournament observations while online (control)', function () {
    AppSettings::setOffline(false);

    $tournamentEvent = eligibleTournamentSyncEvent();
    TournamentObservationQueue::create([
        'log_event_id' => $tournamentEvent->id,
        'tournament_token' => 'tok-1',
        'match_token' => null,
        'event_type' => LogEventType::TOURNAMENT_SYNC->value,
        'payload' => ['token' => 'tok-1'],
        'client_observed_at' => now(),
        'status' => 'pending',
    ]);

    (new ShipTournamentObservations)->handle();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/tournament-observations'));
});

it('submits an unsubmitted match while online (control)', function () {
    AppSettings::setOffline(false);

    CardStatsTelemetryFactory::make();

    app(MtgoManager::class)->retryUnsubmittedMatches();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/matches/report'));
});

it('still resolves card identity from the API while offline — the carve-out', function () {
    AppSettings::setOffline(true);

    ResolveCardsFromDek::run([
        ['mtgo_id' => 12345, 'quantity' => 4, 'sideboard' => false],
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/cards/resolve'));
});
