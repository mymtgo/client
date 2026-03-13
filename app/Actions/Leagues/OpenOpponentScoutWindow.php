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
            ->width(300)
            ->height(80)
            ->minWidth(200)
            ->minHeight(60)
            ->alwaysOnTop(true, 'screen-saver')
            ->frameless()
            ->resizable()
            ->maximizable(false)
            ->fullscreenable(false)
            ->hideMenu()
            ->showDevTools(false)
            ->title('Opponent Scout');
    }
}
