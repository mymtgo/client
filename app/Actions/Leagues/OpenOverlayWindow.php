<?php

namespace App\Actions\Leagues;

use Native\Desktop\Facades\Window;

class OpenOverlayWindow
{
    public static function run(): void
    {
        $alreadyOpen = collect(Window::all())->contains('id', 'overlay');

        if ($alreadyOpen) {
            return;
        }

        Window::open('overlay')
            ->route('leagues.overlay')
            ->width(300)
            ->height(80)
            ->minWidth(200)
            ->minHeight(60)
            ->alwaysOnTop()
            ->frameless()
            ->resizable()
            ->maximizable(false)
            ->fullscreenable(false)
            ->hideMenu()
            ->showDevTools(false)
            ->title('League Overlay');
    }
}
