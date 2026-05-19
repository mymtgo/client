<?php

namespace App\Actions\Tray;

use Native\Desktop\Facades\Window;

class FocusOrOpenMainWindow
{
    public static function run(): void
    {
        $existing = collect(Window::all())->first(
            fn ($w) => $w->getId() === 'main'
        );

        if ($existing !== null) {
            Window::show('main');

            return;
        }

        Window::open('main')
            ->width(1600)
            ->height(900)
            ->minHeight(800)
            ->minWidth(1200)
            ->movable()
            ->title('mymtgo')
            ->hideMenu()
            ->trafficLightsHidden()
            ->rememberState();
    }
}
