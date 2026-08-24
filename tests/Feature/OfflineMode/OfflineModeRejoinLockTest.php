<?php

use App\Facades\AppSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

// The rejoin path dispatches DownloadArchetypes; fake the queue so these tests
// exercise the lock rather than the resync.
beforeEach(fn () => Queue::fake());

// Toggling offline mode OFF starts a cooldown before it can be turned back on.
// The point is friction, not enforcement: the timestamp lives in plain
// settings.json and is deletable, which is accepted. It exists so that grabbing
// a fresh archetype catalogue and immediately going private again costs the
// user a day online rather than two clicks.

it('locks offline mode until tomorrow when rejoining', function () {
    AppSettings::setOffline(true);

    $this->patch(route('settings.offline-mode'), ['enabled' => false])
        ->assertRedirect();

    expect(AppSettings::isOffline())->toBeFalse()
        ->and(AppSettings::isOfflineModeLocked())->toBeTrue();

    $expected = Carbon::now()->toLocal()->addDay()->startOfDay();

    expect(Carbon::parse(AppSettings::offlineModeLockedUntil())->timestamp)
        ->toBe($expected->timestamp);
});

it('does not lock when the user was already online', function () {
    AppSettings::setOffline(false);

    $this->patch(route('settings.offline-mode'), ['enabled' => false])
        ->assertRedirect();

    expect(AppSettings::isOfflineModeLocked())->toBeFalse()
        ->and(AppSettings::offlineModeLockedUntil())->toBeNull();
});

it('refuses to re-enable offline mode while locked', function () {
    AppSettings::setOffline(true);
    $this->patch(route('settings.offline-mode'), ['enabled' => false]);

    $this->patch(route('settings.offline-mode'), ['enabled' => true])
        ->assertSessionHas('error');

    expect(AppSettings::isOffline())->toBeFalse();
});

// The control: without this, the test above would pass even if the toggle were
// simply broken and never re-enabled anything.
it('allows offline mode again once the lock has expired', function () {
    AppSettings::setOffline(true);
    $this->patch(route('settings.offline-mode'), ['enabled' => false]);

    Carbon::setTestNow(Carbon::now()->addDays(2));

    expect(AppSettings::isOfflineModeLocked())->toBeFalse();

    $this->patch(route('settings.offline-mode'), ['enabled' => true])
        ->assertRedirect();

    expect(AppSettings::isOffline())->toBeTrue();

    Carbon::setTestNow();
});

it('reports not locked when no lock has ever been written', function () {
    expect(AppSettings::isOfflineModeLocked())->toBeFalse()
        ->and(AppSettings::offlineModeLockedUntil())->toBeNull();
});
