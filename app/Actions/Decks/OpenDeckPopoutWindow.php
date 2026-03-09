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
            ->height(600)
            ->minWidth(250)
            ->minHeight(300)
            ->alwaysOnTop()
            ->frameless()
            ->resizable()
            ->maximizable(false)
            ->fullscreenable(false)
            ->hideMenu()
            ->showDevTools(false)
            ->title('Deck List');
    }
}
