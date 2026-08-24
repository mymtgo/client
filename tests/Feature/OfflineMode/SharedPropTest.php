<?php

use App\Facades\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Route choice
|--------------------------------------------------------------------------
|
| Deliberately not settings.index: Settings\IndexController sets its own
| page-level 'offlineMode' prop, which wins Inertia's props merge on that
| route and would make this test pass even if the shared prop were removed
| from HandleInertiaRequests entirely. archetypes.index has no competing
| 'offlineMode' key anywhere in its controller/action chain (confirmed via
| grep across app/Http/Controllers), so its response can only carry the
| value if the shared prop is actually wired up. It also needs no fixtures
| or faked disks — GetFilteredArchetypes runs cleanly against an empty,
| migrated database.
*/
it('shares offline mode as a top level prop', function () {
    AppSettings::setOffline(true);

    $this->get(route('archetypes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('offlineMode', true));
});

it('flips the shared prop with the setting', function () {
    AppSettings::setOffline(false);

    $this->get(route('archetypes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('offlineMode', false));
});
