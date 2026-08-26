<?php

namespace App\Actions\Overlay;

use Native\Desktop\Facades\Window;

class CloseDraftNotesWindow
{
    public static function run(): void
    {
        $open = collect(Window::all())->contains(fn ($w) => $w->getId() === OpenDraftNotesWindow::WINDOW_ID);

        if ($open) {
            Window::close(OpenDraftNotesWindow::WINDOW_ID);
        }
    }
}
