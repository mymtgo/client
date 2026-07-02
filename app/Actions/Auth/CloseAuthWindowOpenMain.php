<?php

namespace App\Actions\Auth;

use Native\Desktop\Facades\Window;

/**
 * The post-auth window swap: bring up the main window, drop the auth
 * window. Overlay windows return here with the v1 UI rebuild.
 */
final class CloseAuthWindowOpenMain
{
    public function run(): void
    {
        $mainOpen = collect(Window::all())->contains(fn ($w) => $w->getId() === 'main');

        if (! $mainOpen) {
            Window::open()
                ->width(1600)
                ->height(900)
                ->minHeight(800)
                ->minWidth(1200)
                ->movable()
                ->hideOnClose()
                ->title('mymtgo');
        }

        Window::close('auth');
    }
}
