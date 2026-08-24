<?php

use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('sends the offline mode state to the settings page', function () {
    AppSettings::setOffline(true);

    $this->get(route('settings.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('offlineMode', true)
            ->where('hasArchetypeCatalog', false));
});

it('reflects offline mode being off, not just on', function () {
    AppSettings::setOffline(false);

    $this->get(route('settings.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('offlineMode', false));
});

it('reports a populated archetype catalog when archetype decklists exist', function () {
    ArchetypeDeck::factory()->create();

    $this->get(route('settings.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hasArchetypeCatalog', true));
});

it('reports no archetype catalog when archetype names exist but no decklists do', function () {
    // A fresh install seeds ~877 Archetype rows (names only) with zero
    // ArchetypeDeck rows — EstimateArchetypeLocally needs decklists, not names,
    // so this must still read as "no catalog".
    Archetype::factory()->create(['is_fallback' => false]);

    $this->get(route('settings.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hasArchetypeCatalog', false));
});

it('sends the rejoin cooldown to the settings page', function () {
    AppSettings::setOfflineModeLockedUntil(now()->addDay()->startOfDay()->toIso8601String());

    $this->get(route('settings.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'offlineModeLockedUntil',
            AppSettings::offlineModeLockedUntil()
        ));
});

// Control: without this the assertion above would pass on a hardcoded value.
it('sends a null cooldown when none is set', function () {
    $this->get(route('settings.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('offlineModeLockedUntil', null));
});
