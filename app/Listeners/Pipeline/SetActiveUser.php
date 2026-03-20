<?php

namespace App\Listeners\Pipeline;

use App\Events\UserLoggedIn;
use App\Facades\Mtgo;

class SetActiveUser
{
    public function handle(UserLoggedIn $event): void
    {
        $username = $event->logEvent->username;

        if ($username) {
            Mtgo::setUsername($username);
        }
    }
}
