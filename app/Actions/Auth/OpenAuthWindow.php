<?php

namespace App\Actions\Auth;

use Native\Desktop\Facades\Window;

/**
 * The dedicated sign-in window, pointed at the cloud API's /oauth/authorize
 * page (Discord + email/password are server-rendered there). Opening it
 * stashes a fresh PKCE verifier + state via BuildAuthorizeUrl.
 */
final class OpenAuthWindow
{
    public function __construct(private BuildAuthorizeUrl $buildUrl) {}

    public function run(): void
    {
        $alreadyOpen = collect(Window::all())->contains(fn ($w) => $w->getId() === 'auth');

        if ($alreadyOpen) {
            return;
        }

        Window::open('auth')
            ->url($this->buildUrl->run())
            ->width(480)
            ->height(720)
            ->minWidth(400)
            ->minHeight(600)
            ->movable()
            ->resizable(false)
            ->maximizable(false)
            ->title('Sign in to mymtgo');
    }
}
