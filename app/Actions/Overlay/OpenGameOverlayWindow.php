<?php

namespace App\Actions\Overlay;

use Native\Desktop\Facades\Window;

class OpenGameOverlayWindow
{
    public static function run(): void
    {
        $alreadyOpen = collect(Window::all())->contains(fn ($w) => $w->getId() === 'game-overlay');

        if ($alreadyOpen) {
            return;
        }

        Window::open('game-overlay')
            ->route('overlay.game')
            ->width(320)
            ->height(640)
            ->minWidth(300)
            ->maxWidth(400)
            ->minHeight(400)
            ->rememberState()
            ->alwaysOnTop(true, 'screen-saver')
            ->frameless()
            ->resizable()
            ->movable()
            ->maximizable(false)
            ->fullscreenable(false)
            ->hideMenu()
            ->showDevTools(false)
            ->title('Game overlay');
    }
}
