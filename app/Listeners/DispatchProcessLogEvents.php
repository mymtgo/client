<?php

namespace App\Listeners;

use App\Events\LogEventsIngested;
use App\Jobs\ProcessLogEvents;

class DispatchProcessLogEvents
{
    public function handle(LogEventsIngested $event): void
    {
        ProcessLogEvents::dispatchSync();
    }
}
