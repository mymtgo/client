<?php

use App\Facades\AppSettings;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake());

it('persists league window setting', function () {
    $this->post(route('settings.overlay'), [
        'league_window' => true,
    ])->assertRedirect();

    expect(AppSettings::showLeagueWindow())->toBeTrue();
});

it('validates league window is boolean', function () {
    $this->post(route('settings.overlay'), [
        'league_window' => 'not-a-bool',
    ])->assertSessionHasErrors(['league_window']);
});

it('opens the game overlay window when enabled', function () {
    $this->post(route('settings.overlay'), ['game_overlay' => true])->assertRedirect();

    expect(AppSettings::showGameOverlay())->toBeTrue();
});

it('closes the game overlay window when disabled', function () {
    AppSettings::setShowGameOverlay(true);

    $this->post(route('settings.overlay'), ['game_overlay' => false])->assertRedirect();

    expect(AppSettings::showGameOverlay())->toBeFalse();
});

it('persists section toggles without touching the master', function () {
    AppSettings::setShowGameOverlay(true);

    $this->post(route('settings.overlay'), ['overlay_show_sideboard' => false])->assertRedirect();

    expect(AppSettings::overlayShowSideboard())->toBeFalse();
    expect(AppSettings::showGameOverlay())->toBeTrue();
});
