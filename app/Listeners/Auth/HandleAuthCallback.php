<?php

namespace App\Listeners\Auth;

use App\Actions\Auth\HandleOAuthCallback;
use Native\Desktop\Events\App\OpenedFromURL;

/**
 * Adapts NativePHP's deep-link event (open-url on macOS, second-instance on
 * Windows/Linux) to the OAuth callback handler.
 */
class HandleAuthCallback
{
    public function __construct(private HandleOAuthCallback $handler) {}

    public function handle(OpenedFromURL $event): void
    {
        $this->handler->run($event->url);
    }
}
