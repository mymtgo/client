<?php

namespace App\Listeners\Pipeline;

use App\Actions\Leagues\ProcessLeagueEvents;

class ProcessLeagueJoin
{
    public function handle(object $event): void
    {
        // Delegate to the existing action which handles both event types
        // by querying unprocessed league events from the database
        ProcessLeagueEvents::run();
    }
}
