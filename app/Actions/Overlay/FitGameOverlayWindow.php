<?php

namespace App\Actions\Overlay;

use App\Facades\AppSettings;
use Native\Desktop\Facades\Window;

/**
 * Resize the open overlay to fit its content. Called by the overlay page
 * after it has measured its fixed region, because `rememberState()` restores
 * whatever height the window last had, which may have been set while a very
 * different set of sections was enabled.
 *
 * A collapsed overlay hugs its header and tab bar instead, so a header that
 * changes shape mid-match never re-opens the card list.
 */
class FitGameOverlayWindow
{
    public static function run(int $fixedHeight, int $barHeight = 0): void
    {
        $window = OpenGameOverlayWindow::find();

        if (! $window) {
            return;
        }

        $height = AppSettings::overlayCollapsed()
            ? ComputeGameOverlayHeight::collapsed($fixedHeight, $barHeight)
            : ComputeGameOverlayHeight::fromSettings($fixedHeight);

        Window::resize((int) ($window->width ?? OpenGameOverlayWindow::DEFAULT_WIDTH), $height, OpenGameOverlayWindow::ID);
    }
}
