<?php

use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('renders the privacy settings page', function () {
    $this->get(route('settings.privacy'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Privacy')
            ->where('currentPage', 'privacy')
            ->has('pendingMatches')
            ->where('offlineModeLockedUntil', null)
            ->where('hasArchetypeCatalog', false));
});

it('sends the offline mode state to the privacy page', function () {
    AppSettings::setOffline(true);

    $this->get(route('settings.privacy'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('offlineMode', true));
});

it('reports a populated archetype catalog when archetype decklists exist', function () {
    ArchetypeDeck::factory()->create();

    $this->get(route('settings.privacy'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hasArchetypeCatalog', true));
});

it('reports no archetype catalog when archetype names exist but no decklists do', function () {
    Archetype::factory()->create(['is_fallback' => false]);

    $this->get(route('settings.privacy'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hasArchetypeCatalog', false));
});

it('sends the rejoin cooldown to the privacy page', function () {
    AppSettings::setOfflineModeLockedUntil(now()->addDay()->startOfDay()->toIso8601String());

    $this->get(route('settings.privacy'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'offlineModeLockedUntil',
            AppSettings::offlineModeLockedUntil()
        ));
});
