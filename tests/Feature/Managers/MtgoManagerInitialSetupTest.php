<?php

use App\Facades\AppSettings;
use App\Managers\MtgoManager;
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
    expect(AppSettings::hidePhantomLeagues())->toBeFalse();            // seeded (new)
    expect(AppSettings::isDebugMode())->toBeTrue();                    // untouched
    expect(AppSettings::showLeagueWindow())->toBeFalse();              // seeded (new)
    expect(AppSettings::showOpponentWindow())->toBeFalse();            // seeded (new)
    expect(AppSettings::showDeckWindow())->toBeFalse();                // seeded (new)
    expect(AppSettings::downloadImagesLocally())->toBeFalse();         // seeded (new)
    expect(AppSettings::systemTimezone())->toBeString();               // seeded (new)
    expect(AppSettings::deviceId())->toBeString();                     // seeded (new, uuid)
});
