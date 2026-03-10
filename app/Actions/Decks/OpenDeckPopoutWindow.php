<?php

namespace App\Actions\Decks;

use Native\Desktop\Facades\Window;

class OpenDeckPopoutWindow
{
    public static function run(int $deckId): void
    {
        $windowId = "deck-popout-{$deckId}";

        $alreadyOpen = collect(Window::all())->contains(fn ($w) => $w->getId() === $windowId);

        if ($alreadyOpen) {
            return;
        }

        // Close any other deck popout windows
        collect(Window::all())
            ->filter(fn ($w) => str_starts_with($w->getId(), 'deck-popout-') && $w->getId() !== $windowId)
            ->each(fn ($w) => Window::close($w->getId()));

        Window::open($windowId)
            ->route('decks.popout', ['deck' => $deckId])
            ->width(300)
            ->height(700)
            ->minWidth(300)
            ->maxWidth(400)
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
