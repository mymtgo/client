<?php

use App\Actions\Cards\EnqueueCardStats;
use App\Actions\Tournaments\EnqueueTournamentObservations;
use App\Enums\LogEventType;
use App\Facades\AppSettings;
use App\Jobs\DownloadArchetypes;
use App\Managers\MtgoManager;
use App\Models\CardStatShipQueue;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\TournamentObservationQueue;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\CardStatsTelemetryFactory;

uses(RefreshDatabase::class);

/**
 * A tournament log event that ExtractTournamentPayload can turn into a
 * non-empty payload, so EnqueueTournamentObservations has a row it would
 * queue if the offline guard were not there.
 */
function makeEligibleTournamentSyncEvent(): LogEvent
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

/**
 * `Schedule` is normally populated by the `withSchedule()` callback wired
 * up in bootstrap/app.php, but that callback only fires when Laravel's
 * console `Application` bootstraps (i.e. when an artisan command actually
 * runs) — not merely by resolving `Schedule::class` from the container.
 * Registering `MtgoManager::schedule()` against a fresh `Schedule` instance
 * directly sidesteps that timing and gives every test a deterministic,
 * fully-populated schedule to inspect.
 */
function scheduledEventNamed(string $name): Event
{
    $schedule = app(Schedule::class);

    if (empty($schedule->events())) {
        app(MtgoManager::class)->schedule($schedule);
    }

    $events = collect($schedule->events());

    return $events->first(fn ($event) => str_contains((string) $event->description, $name)
        || str_contains((string) $event->mutexName(), $name))
        ?? throw new RuntimeException("No scheduled event matching {$name}");
}

it('skips the shipping jobs while offline', function () {
    AppSettings::setOffline(true);

    expect(scheduledEventNamed('ship_card_stats')->filtersPass(app()))->toBeFalse()
        ->and(scheduledEventNamed('ship_tournament_observations')->filtersPass(app()))->toBeFalse()
        ->and(scheduledEventNamed('submit_matches')->filtersPass(app()))->toBeFalse()
        ->and(scheduledEventNamed('enqueue_card_stats')->filtersPass(app()))->toBeFalse();
});

it('runs the shipping jobs while online', function () {
    AppSettings::setOffline(false);

    expect(scheduledEventNamed('ship_card_stats')->filtersPass(app()))->toBeTrue();
});

it('does not enqueue card stats while offline', function () {
    // Fixture is deliberately eligible — same shape the "control" test below
    // proves actually gets queued — so this is testing the offline guard,
    // not an empty query result.
    CardStatsTelemetryFactory::make();
    AppSettings::setOffline(true);

    EnqueueCardStats::run();

    expect(CardStatShipQueue::count())->toBe(0);
});

it('enqueues card stats for the same fixture while online (control)', function () {
    CardStatsTelemetryFactory::make();
    AppSettings::setOffline(false);

    EnqueueCardStats::run();

    expect(CardStatShipQueue::count())->toBeGreaterThan(0);
});

it('does not enqueue tournament observations while offline', function () {
    makeEligibleTournamentSyncEvent();
    AppSettings::setOffline(true);

    EnqueueTournamentObservations::run();

    expect(TournamentObservationQueue::count())->toBe(0);
});

it('enqueues tournament observations for the same fixture while online (control)', function () {
    makeEligibleTournamentSyncEvent();
    AppSettings::setOffline(false);

    EnqueueTournamentObservations::run();

    expect(TournamentObservationQueue::count())->toBeGreaterThan(0);
});

it('does not download archetypes at boot while offline', function () {
    AppSettings::setOffline(true);
    Queue::fake();

    app(MtgoManager::class)->runInitialSetup();

    Queue::assertNotPushed(DownloadArchetypes::class);
});
