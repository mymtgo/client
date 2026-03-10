<?php

namespace App\Actions\Leagues;

use Native\Desktop\Facades\Window;

class CloseOpponentScoutWindow
{
    public static function run(): void
    {
        $existing = collect(Window::all())->contains(fn ($w) => $w->getId() === 'opponent-scout');

        if ($existing) {
            Window::close('opponent-scout');
        }
    }
}
