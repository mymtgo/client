<?php

namespace App\Actions\Decks;

use Native\Desktop\Facades\Window;

class OpenDeckPopoutWindow
{
    public static function run(): void
    {
        $alreadyOpen = collect(Window::all())->contains(fn ($w) => $w->getId() === 'deck-popout');

        if ($alreadyOpen) {
            return;
        }

        Window::open('deck-popout')
            ->route('decks.popout')
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
            ->title('Deck Odds');
    }
}
