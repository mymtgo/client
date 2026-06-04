<?php

namespace App\Actions\Leagues;

use Native\Desktop\Facades\Window;

class OpenOpponentScoutWindow
{
    public static function run(): void
    {
        $alreadyOpen = collect(Window::all())->contains(fn ($w) => $w->getId() === 'opponent-scout');

        if ($alreadyOpen) {
            return;
        }

        Window::open('opponent-scout')
            ->route('leagues.opponent-scout')
            ->width(340)
            ->height(200)
            ->minWidth(280)
            ->alwaysOnTop(true, 'screen-saver')
            ->frameless()
            ->resizable()
            ->transparent()
            ->maximizable(false)
            ->fullscreenable(false)
            ->hideMenu()
            ->showDevTools(false)
            ->title('Opponent Scout');
    }
}
