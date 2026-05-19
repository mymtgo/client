<?php

namespace App\Listeners\Tray;

use App\Actions\Tray\FocusOrOpenMainWindow;
use Native\Desktop\Events\MenuBar\MenuBarClicked;

class HandleTrayClick
{
    public function handle(MenuBarClicked $event): void
    {
        FocusOrOpenMainWindow::run();
    }
}
