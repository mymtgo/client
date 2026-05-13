<?php

namespace App\Actions\Pipeline\Handlers;

use App\Actions\Tournaments\EnqueueTournamentObservations;
use App\Models\LogEvent;
use App\Support\PipelineContext;

/**
 * @see HandleTournamentSync for the rationale.
 */
class HandleTournamentRoundInfo implements Handler
{
    public function handle(LogEvent $event, PipelineContext $context): void
    {
        EnqueueTournamentObservations::run();
    }
}
