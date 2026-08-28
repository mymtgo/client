<?php

use App\Actions\Overlay\ComputeGameOverlayHeight;
use App\Actions\Overlay\FitGameOverlayWindow;
use App\Facades\AppSettings;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as WindowInstance;

it('resizes the open overlay to the measured fixed content, keeping its current width', function () {
    AppSettings::setOverlayShowOpponent(true);
    AppSettings::setOverlayShowDrawOdds(false);
    AppSettings::setOverlayShowReveals(false);
    AppSettings::setOverlayShowSideboard(false);

    $open = (new WindowInstance('game-overlay'))->fromRuntimeWindow((object) ['width' => 352, 'height' => 640]);

    Window::shouldReceive('all')->andReturn([new WindowInstance('main'), $open]);
    Window::shouldReceive('resize')->once()->with(352, 137, 'game-overlay');

    FitGameOverlayWindow::run(fixedHeight: 137);
});

it('adds the tab area when a tab section is enabled', function () {
    AppSettings::setOverlayShowOpponent(true);
    AppSettings::setOverlayShowReveals(true);

    $open = (new WindowInstance('game-overlay'))->fromRuntimeWindow((object) ['width' => 320, 'height' => 200]);

    Window::shouldReceive('all')->andReturn([$open]);
    Window::shouldReceive('resize')->once()->with(320, 100 + ComputeGameOverlayHeight::TAB_AREA, 'game-overlay');

    FitGameOverlayWindow::run(fixedHeight: 100);
});

it('does nothing when the overlay window is not open', function () {
    Window::shouldReceive('all')->andReturn([new WindowInstance('main')]);
    Window::shouldReceive('resize')->never();

    FitGameOverlayWindow::run(fixedHeight: 100);
});
