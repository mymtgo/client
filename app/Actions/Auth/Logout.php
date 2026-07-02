<?php

namespace App\Actions\Auth;

use Native\Desktop\Facades\Window;

/**
 * User-initiated sign-out (and the recovery path after a server-side device
 * revocation): wipe tokens, drop every non-auth window, show the sign-in.
 */
final class Logout
{
    public function __construct(
        private ClearSession $clearSession,
        private OpenAuthWindow $openAuthWindow,
    ) {}

    public function run(): void
    {
        $this->clearSession->run();

        collect(Window::all())
            ->filter(fn ($w) => $w->getId() !== 'auth')
            ->each(fn ($w) => Window::close($w->getId()));

        $this->openAuthWindow->run();
    }
}
