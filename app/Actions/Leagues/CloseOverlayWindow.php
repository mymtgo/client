<?php

namespace App\Actions\Leagues;

use Native\Desktop\Facades\Window;

class CloseOverlayWindow
{
    public static function run(): void
    {
        $existing = collect(Window::all())->contains(fn ($w) => $w->getId() === 'overlay');

        if ($existing) {
            Window::close('overlay');
        }
    }
}
