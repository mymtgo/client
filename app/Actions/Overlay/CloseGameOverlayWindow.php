<?php

namespace App\Actions\Overlay;

use Native\Desktop\Facades\Window;

class CloseGameOverlayWindow
{
    public static function run(): void
    {
        $open = collect(Window::all())->contains(fn ($w) => $w->getId() === 'game-overlay');

        if ($open) {
            Window::close('game-overlay');
        }
    }
}
