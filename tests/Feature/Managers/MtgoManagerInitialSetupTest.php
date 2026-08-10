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
    expect(AppSettings::shouldTransmitMatches())->toBeTrue();          // seeded
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
