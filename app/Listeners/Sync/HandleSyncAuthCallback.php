<?php

namespace App\Listeners\Sync;

use App\Actions\Sync\Auth\HandleSyncOauthCallback;
use Native\Desktop\Events\App\OpenedFromURL;

/**
 * Adapts NativePHP's deep-link event (open-url on macOS, second-instance on
 * Windows/Linux) to the sync OAuth callback handler.
 */
class HandleSyncAuthCallback
{
    public function __construct(private HandleSyncOauthCallback $handler) {}

    public function handle(OpenedFromURL $event): void
    {
        $this->handler->run((string) $event->url);
    }
}
