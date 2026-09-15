<?php

use App\Facades\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('overlay');
});

it('renders the overlays settings page with every overlay prop', function () {
    $this->get(route('settings.overlays'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Overlays')
            ->where('currentPage', 'overlays')
            ->has('leagueWindowEnabled')
            ->has('gameOverlayEnabled')
            ->has('overlayShowOpponent')
            ->has('overlayShowDrawOdds')
            ->has('overlayShowSideboard')
            ->has('overlayShowReveals')
            ->where('draftNotesWindowEnabled', true)
            ->where('overlayBackgroundUrl', null));
});

it('resolves the overlay background url when the file exists', function () {
    Storage::disk('overlay')->put('backgrounds/bg.png', 'img');
    AppSettings::setOverlayBackgroundPath('backgrounds/bg.png');

    $this->get(route('settings.overlays'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('overlayBackgroundUrl', Storage::disk('overlay')->url('backgrounds/bg.png')));
});
