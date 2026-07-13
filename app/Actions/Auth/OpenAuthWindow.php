<?php

namespace App\Actions\Auth;

use Native\Desktop\Facades\Window;

/**
 * The dedicated sign-in window: a local signed-out page whose button sends
 * the user to the website in their system browser. The PKCE stash happens
 * when that button is clicked (OpenWebsiteLoginController), not here.
 */
final class OpenAuthWindow
{
    public function run(): void
    {
        $alreadyOpen = collect(Window::all())->contains(fn ($w) => $w->getId() === 'auth');

        if ($alreadyOpen) {
            return;
        }

        Window::open('auth')
            ->url(route('auth.login'))
            ->width(440)
            ->height(560)
            ->movable()
            ->resizable(false)
            ->maximizable(false)
            ->title('Sign in to mymtgo');
    }
}
