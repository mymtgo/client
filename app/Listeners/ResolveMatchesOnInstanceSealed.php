<?php

namespace App\Listeners;

use App\Actions\Matches\ReconcileStuckMatches;
use App\Events\LogInstanceSealed;

class ResolveMatchesOnInstanceSealed
{
    public function handle(LogInstanceSealed $event): void
    {
        ReconcileStuckMatches::run();
    }
}
