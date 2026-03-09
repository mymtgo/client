<?php

namespace App\Actions\Decks;

use Native\Desktop\Facades\Window;

class OpenDeckPopoutWindow
{
    public static function run(int $deckId): void
    {
        $windowId = "deck-popout-{$deckId}";

        $alreadyOpen = collect(Window::all())->contains('id', $windowId);

        if ($alreadyOpen) {
            return;
        }

        Window::open($windowId)
            ->route('decks.popout', ['deck' => $deckId])
            ->width(300)
            ->height(700)
            ->minWidth(300)
            ->minHeight(400)
            ->rememberState()
            ->alwaysOnTop()
            ->frameless()
            ->resizable()
            ->movable()
            ->maximizable(false)
            ->fullscreenable(false)
            ->hideMenu()
            ->showDevTools(false)
            ->title('Deck List');
    }
}
