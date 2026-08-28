<?php

namespace App\Actions\Overlay;

use Native\Desktop\Facades\Window;

/**
 * Resize the open overlay to fit its content. Called by the overlay page
 * after it has measured its fixed region, because `rememberState()` restores
 * whatever height the window last had, which may have been set while a very
 * different set of sections was enabled.
 */
class FitGameOverlayWindow
{
    public static function run(int $fixedHeight): void
    {
        $window = collect(Window::all())->first(fn ($w) => $w->getId() === OpenGameOverlayWindow::ID);

        if (! $window) {
            return;
        }

        Window::resize(
            (int) ($window->width ?? OpenGameOverlayWindow::DEFAULT_WIDTH),
            ComputeGameOverlayHeight::fromSettings($fixedHeight),
            OpenGameOverlayWindow::ID,
        );
    }
}
