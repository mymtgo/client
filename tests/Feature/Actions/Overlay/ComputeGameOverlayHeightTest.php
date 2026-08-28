<?php

use App\Actions\Overlay\ComputeGameOverlayHeight;
use App\Facades\AppSettings;

it('uses the fixed content height alone when no tab section is enabled', function () {
    expect(ComputeGameOverlayHeight::run(hasTabSections: false, fixedHeight: 150))->toBe(150);
});

it('adds the tab area on top of the fixed content when a tab section is enabled', function () {
    expect(ComputeGameOverlayHeight::run(hasTabSections: true, fixedHeight: 100))
        ->toBe(100 + ComputeGameOverlayHeight::TAB_AREA);
});

it('never goes below the minimum window height', function () {
    expect(ComputeGameOverlayHeight::run(hasTabSections: false, fixedHeight: 10))
        ->toBe(ComputeGameOverlayHeight::MIN_HEIGHT);
});

it('estimates the fixed content from settings when nothing has been measured yet', function () {
    AppSettings::setOverlayShowOpponent(true);
    AppSettings::setOverlayShowDrawOdds(false);
    AppSettings::setOverlayShowReveals(false);
    AppSettings::setOverlayShowSideboard(false);

    expect(ComputeGameOverlayHeight::fromSettings())->toBe(ComputeGameOverlayHeight::OPPONENT_HEADER_ESTIMATE);

    AppSettings::setOverlayShowDrawOdds(true);

    expect(ComputeGameOverlayHeight::fromSettings())
        ->toBe(ComputeGameOverlayHeight::OPPONENT_HEADER_ESTIMATE + ComputeGameOverlayHeight::TAB_AREA);
});

it('estimates the settings hint when every section is off', function () {
    AppSettings::setOverlayShowOpponent(false);
    AppSettings::setOverlayShowDrawOdds(false);
    AppSettings::setOverlayShowReveals(false);
    AppSettings::setOverlayShowSideboard(false);

    expect(ComputeGameOverlayHeight::fromSettings())->toBe(ComputeGameOverlayHeight::MIN_HEIGHT);
});
