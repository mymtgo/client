<?php

namespace App\Listeners\Tray;

use App\Actions\Tray\FocusOrOpenMainWindow;
use App\Events\TrayOpenRequested;
use Native\Desktop\Events\MenuBar\MenuBarClicked;

class HandleTrayClick
{
    public function handle(MenuBarClicked|TrayOpenRequested $event): void
    {
        FocusOrOpenMainWindow::run();
    }
}
