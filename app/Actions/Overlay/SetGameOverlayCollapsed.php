<?php

namespace App\Actions\Overlay;

use App\Facades\AppSettings;
use Native\Desktop\Facades\Window;

/**
 * Collapse the overlay to its header and tab bar, or expand it back. The
 * height the player had sized it to is remembered on the way down and
 * restored on the way up; a repeated collapse keeps the first height rather
 * than remembering the collapsed strip.
 */
class SetGameOverlayCollapsed
{
    public static function run(bool $collapsed, int $fixedHeight, int $barHeight): void
    {
        $window = OpenGameOverlayWindow::find()?->toArray();
        $wasCollapsed = AppSettings::overlayCollapsed();

        if ($collapsed && ! $wasCollapsed && ! empty($window['height'])) {
            AppSettings::setOverlayExpandedHeight((int) $window['height']);
        }

        AppSettings::setOverlayCollapsed($collapsed);

        if ($collapsed) {
            FitGameOverlayWindow::run($fixedHeight, $barHeight);

            return;
        }

        $height = AppSettings::overlayExpandedHeight() ?? ComputeGameOverlayHeight::fromSettings($fixedHeight);
        AppSettings::setOverlayExpandedHeight(null);

        if ($window) {
            Window::resize((int) ($window['width'] ?? OpenGameOverlayWindow::DEFAULT_WIDTH), $height, OpenGameOverlayWindow::ID);
        }
    }
}
