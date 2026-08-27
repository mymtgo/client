<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('cards');
    Storage::fake('overlay');
});

it('renders the settings page with the game overlay props', function () {
    $this->get(route('settings.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('gameOverlayEnabled')
            ->has('overlayShowOpponent')
            ->has('overlayShowDrawOdds')
            ->has('overlayShowSideboard')
            ->where('draftNotesWindowEnabled', true)
            ->etc()
        );
});
