<?php

namespace App\Actions\Decks;

use Native\Desktop\Facades\Window;

class CloseDeckPopoutWindow
{
    public static function run(): void
    {
        collect(Window::all())
            ->filter(fn ($w) => str_starts_with($w->getId(), 'deck-popout-'))
            ->each(fn ($w) => Window::close($w->getId()));
    }
}
