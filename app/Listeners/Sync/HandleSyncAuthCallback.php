<?php

namespace App\Listeners\Sync;

use App\Actions\Tray\FocusOrOpenMainWindow;
use Native\Desktop\Events\App\OpenedFromURL;

/**
 * NativePHP's deep-link event (open-url on macOS, second-instance on
 * Windows/Linux). In the farewell release (0.47.0) no deep link is handled:
 * it brings the main window, which only shows the farewell screen, forward.
 */
class HandleSyncAuthCallback
{
    public function handle(OpenedFromURL $event): void
    {
        FocusOrOpenMainWindow::run();
    }
}
