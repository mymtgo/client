<?php

namespace App\Actions\Pipeline\Handlers;

use App\Actions\Tournaments\EnqueueTournamentObservations;
use App\Models\LogEvent;
use App\Support\PipelineContext;

/**
 * Thin wrapper that delegates to EnqueueTournamentObservations.
 *
 * All tournament event_types funnel into the same enqueue action, which is
 * idempotent (insertOrIgnore on log_event_id). Per-event-type handlers exist
 * so the walker's event_type → handler map is exhaustive; the underlying
 * action handles the actual payload extraction per type.
 */
class HandleTournamentSync implements Handler
{
    public function handle(LogEvent $event, PipelineContext $context): void
    {
        EnqueueTournamentObservations::run();
    }
}
