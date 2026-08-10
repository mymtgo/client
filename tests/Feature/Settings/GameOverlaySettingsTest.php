<?php

use App\Facades\AppSettings;

it('defaults the overlay off and every section on', function () {
    expect(AppSettings::showGameOverlay())->toBeFalse();
    expect(AppSettings::overlayShowOpponent())->toBeTrue();
    expect(AppSettings::overlayShowDrawOdds())->toBeTrue();
    expect(AppSettings::overlayShowSideboard())->toBeTrue();
});

it('inherits the overlay setting from the legacy deck window key', function () {
    AppSettings::set('deck_window', true);

    expect(AppSettings::showGameOverlay())->toBeTrue();
});

it('inherits the overlay setting from the legacy opponent window key', function () {
    AppSettings::set('opponent_window', true);

    expect(AppSettings::showGameOverlay())->toBeTrue();
});

it('prefers an explicit game overlay setting over the legacy keys', function () {
    AppSettings::set('deck_window', true);
    AppSettings::setShowGameOverlay(false);

    expect(AppSettings::showGameOverlay())->toBeFalse();
});

it('persists section toggles', function () {
    AppSettings::setOverlayShowSideboard(false);

    expect(AppSettings::overlayShowSideboard())->toBeFalse();
    expect(AppSettings::overlayShowDrawOdds())->toBeTrue();
});
