<?php

namespace App\Events;

use App\Models\LogEvent;
use Illuminate\Foundation\Events\Dispatchable;

class UserLoggedIn
{
    use Dispatchable;

    public function __construct(public LogEvent $logEvent) {}
}
