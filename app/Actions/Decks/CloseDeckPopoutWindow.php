<?php

namespace App\Actions\Decks;

use Native\Desktop\Facades\Window;

class CloseDeckPopoutWindow
{
    public static function run(): void
    {
        $open = collect(Window::all())->contains(fn ($w) => $w->getId() === 'deck-popout');

        if ($open) {
            Window::close('deck-popout');
        }
    }
}
