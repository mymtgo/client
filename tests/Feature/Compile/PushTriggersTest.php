<?php

use App\Actions\Compile\PushTriggers;
use App\Actions\Logs\IngestLogInstance;
use App\Facades\Mtgo;
use App\Jobs\PushOutboxJob;
use App\Models\AppAccount;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\Outbox;
use App\Models\RawArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const TRIGGERS_FIXTURE_TOKEN = 'f5e33a1f-c2e7-4678-b30d-309b63f17a40';

function triggersSetup(): void
{
    Storage::fake('archive');
    Queue::fake();

    $path = sys_get_temp_dir().'/push-triggers-fixture.log';
    copy(base_path('tests/fixtures/mtgo_league_join_drop.log'), $path);
    IngestLogInstance::run($path);

    // Ingestion stamps created_at = now; age the rows so the fixture match
    // reads as quiet (outside the inactivity debounce).
    LogEvent::query()->update(['created_at' => now()->subMinutes(5)]);

    AppAccount::create([
        'user_id' => 1,
        'mtgo_player_id' => 147160,
        'mtgo_username' => 'anticloser',
        'active' => true,
    ]);
    Mtgo::setUsername('anticloser');
}

it('compiles, archives, enqueues, and dispatches a push for a quiet match', function () {
    triggersSetup();

    app(PushTriggers::class)->run();

    // The fixture holds exactly one token with real game traffic.
    expect(Outbox::pending()->count())->toBe(1);
    expect(Outbox::first()->match_key)->toBe(TRIGGERS_FIXTURE_TOKEN);
    expect(RawArchive::where('match_key', TRIGGERS_FIXTURE_TOKEN)->count())->toBe(1);
    Queue::assertPushed(PushOutboxJob::class);
});

it('is idempotent across runs — no version bump, no duplicate archive', function () {
    triggersSetup();

    app(PushTriggers::class)->run();
    app(PushTriggers::class)->run();

    expect(Outbox::count())->toBe(1);
    expect(Outbox::first()->file_version)->toBe(1);
    expect(RawArchive::count())->toBe(1);
});

it('never enqueues an observed token with only state changes', function () {
    Storage::fake('archive');
    Queue::fake();
    AppAccount::create(['user_id' => 1, 'mtgo_username' => 'anticloser', 'active' => true]);
    Mtgo::setUsername('anticloser');

    $instance = LogInstance::factory()->create();
    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'event_type' => 'match_state_changed',
        'match_token' => 'observed-tok',
        'raw_text' => 'Match State Changed ... MatchJoinedEventUnderwayState',
    ]);

    app(PushTriggers::class)->run();

    expect(Outbox::count())->toBe(0);
});

it('skips compiling while a match is still active inside the debounce window', function () {
    triggersSetup();

    // Make the token's traffic look live: newest event just landed.
    LogEvent::where('match_token', TRIGGERS_FIXTURE_TOKEN)
        ->latest('id')->first()
        ->update(['created_at' => now()]);

    app(PushTriggers::class)->run();

    expect(Outbox::count())->toBe(0);
});
