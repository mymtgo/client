<?php

use App\Facades\AppSettings;
use App\Jobs\DownloadArchetypes;
use App\Managers\MtgoManager;
use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('seeds all defaults on first run and leaves existing values untouched', function () {
    Queue::fake();

    AppSettings::setLogPath('C:\\existing');
    AppSettings::setDebugMode(true);

    (new MtgoManager)->runInitialSetup();

    expect(AppSettings::logPath())->toBe('C:\\existing');              // untouched
    expect(AppSettings::logDataPath())->toBeString();                  // seeded
    expect(AppSettings::isOffline())->toBeFalse();                     // seeded
    expect(AppSettings::isWatcherActive())->toBeTrue();                // seeded (new)
    expect(AppSettings::isDebugMode())->toBeTrue();                    // untouched
    expect(AppSettings::showLeagueWindow())->toBeFalse();              // seeded (new)
    expect(AppSettings::showGameOverlay())->toBeFalse();               // default, no seeding needed
    expect(AppSettings::overlayShowOpponent())->toBeTrue();            // default, no seeding needed
    expect(AppSettings::overlayShowDrawOdds())->toBeTrue();            // default, no seeding needed
    expect(AppSettings::overlayShowSideboard())->toBeTrue();           // default, no seeding needed
    expect(AppSettings::downloadImagesLocally())->toBeFalse();         // seeded (new)
    expect(AppSettings::systemTimezone())->toBeString();               // seeded (new)
    expect(AppSettings::deviceId())->toBeString();                     // seeded (new, uuid)
});

it('dispatches DownloadArchetypes when only fallback archetypes exist', function () {
    Queue::fake();

    expect(Archetype::count())->toBeGreaterThan(0)
        ->and(Archetype::where('is_fallback', false)->exists())->toBeFalse();

    (new MtgoManager)->runInitialSetup();

    Queue::assertPushed(DownloadArchetypes::class);
});

it('does not dispatch DownloadArchetypes when non-fallback archetypes exist', function () {
    Queue::fake();

    Archetype::factory()->create(['is_fallback' => false]);

    (new MtgoManager)->runInitialSetup();

    Queue::assertNotPushed(DownloadArchetypes::class);
});

it('does not reseed offline_mode when a boot-time read cannot confirm it was never set', function () {
    Queue::fake();

    // runInitialSetup() runs on every boot with no first-run gate. A transient
    // read failure (AV scanner, momentary lock contention) makes get() return
    // null exactly like a genuinely unset key would — offlineModeNeverSet()
    // exists precisely so this method can tell the two apart and skip seeding
    // when it can't. This double simulates that failure directly, since the
    // global Storage::fake()-backed AppSettings has no way to fail a read.
    // Every other setting is pre-populated so only the offline_mode branch
    // is exercised — isolating the assertion instead of tripping every other
    // seed check the same ambiguity would also affect.
    $spy = new class extends App\Settings\AppSettings
    {
        public bool $setOfflineCalled = false;

        protected array $store = [
            'log_path' => 'C:\\already-configured',
            'log_data_path' => 'C:\\already-configured-data',
            'device_id' => 'existing-device',
            'watcher_active' => true,
            'debug_mode' => false,
            'league_window' => false,
            'local_images' => false,
            'system_tz' => 'UTC',
        ];

        public function get(string $key, mixed $default = null): mixed
        {
            return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
        }

        public function set(string $key, mixed $value): void
        {
            $this->store[$key] = $value;
        }

        public function forget(string $key): void
        {
            unset($this->store[$key]);
        }

        public function offlineModeNeverSet(): bool
        {
            return false;
        }

        public function setOffline(bool $value): void
        {
            $this->setOfflineCalled = true;
            $this->store['offline_mode'] = $value;
        }

        public function isOffline(): bool
        {
            return (bool) ($this->store['offline_mode'] ?? false);
        }
    };

    AppSettings::swap($spy);

    (new MtgoManager)->runInitialSetup();

    expect($spy->setOfflineCalled)->toBeFalse();
});

// Control for the test above: a genuine fresh install (offline_mode
// genuinely absent, not just unreadable) must still seed false — proving
// the fix skips reseeding only under real ambiguity, not unconditionally.
// This is also asserted in "seeds all defaults on first run" above; kept
// here too, side by side with the failure case, so the contrast is explicit.
it('still seeds offline_mode: false on a genuine fresh install', function () {
    Queue::fake();

    (new MtgoManager)->runInitialSetup();

    expect(AppSettings::isOffline())->toBeFalse();
});
