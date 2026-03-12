<?php

namespace App\Listeners;

use Native\Desktop\Events\Windows\WindowClosed;
use Native\Desktop\Facades\Window;

class CloseChildWindows
{
    public function handle(WindowClosed $event): void
    {
        if ($event->id !== 'main') {
            return;
        }

        collect(Window::all())
            ->filter(fn ($w) => $w->getId() !== 'main')
            ->each(fn ($w) => Window::close($w->getId()));
    }
}
