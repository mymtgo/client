<?php

use App\Actions\Overlay\ComputeGameOverlayHeight;
use App\Actions\Overlay\OpenGameOverlayWindow;
use App\Facades\AppSettings;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as WindowInstance;

it('opens an opponent-only overlay short instead of at the full default height', function () {
    AppSettings::setOverlayShowOpponent(true);
    AppSettings::setOverlayShowDrawOdds(false);
    AppSettings::setOverlayShowReveals(false);
    AppSettings::setOverlayShowSideboard(false);

    $window = new WindowInstance('main');
    Window::fake()->alwaysReturnWindows([$window]);

    OpenGameOverlayWindow::run();

    Window::assertOpened('game-overlay');
    expect($window->height)->toBe(ComputeGameOverlayHeight::OPPONENT_HEADER_ESTIMATE);
    expect($window->minHeight)->toBe(ComputeGameOverlayHeight::MIN_HEIGHT);
});

it('opens at the full height when a tab section is enabled', function () {
    AppSettings::setOverlayShowOpponent(true);
    AppSettings::setOverlayShowDrawOdds(true);

    $window = new WindowInstance('main');
    Window::fake()->alwaysReturnWindows([$window]);

    OpenGameOverlayWindow::run();

    expect($window->height)->toBe(ComputeGameOverlayHeight::OPPONENT_HEADER_ESTIMATE + ComputeGameOverlayHeight::TAB_AREA);
});
